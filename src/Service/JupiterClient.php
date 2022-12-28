<?php

namespace Connected\JupiterBundle\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class JupiterClient implements JupiterClientInterface
{

    public const DOCUMENT_STATUT_DRAFT = 'draft';
    public const DOCUMENT_STATUT_ACTIVE = 'active';
    public const DOCUMENT_STATUT_INACTIVE = 'inactive';
    /**
     * @var string|null
     */
    protected $token = null;

    /**
     * @var string
     */
    protected $apiUrl;

    /**
     * @var string
     */
    protected $xApiKey;

    /**
     * @var string
     */
    protected $userLdap;

    /**
     * @var Client
     */
    protected $guzzleClient;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        string $apiUrl,
        string $xApiKey,
        TokenStorageInterface $tokenStorage,
        LoggerInterface $logger
    ) {
        $this->xApiKey = $xApiKey;
        $this->apiUrl = $apiUrl;
        $this->userLdap = $tokenStorage->getToken() !== null ? strtolower($tokenStorage->getToken()->getUserIdentifier()) : 'testinfo';
        $this->logger = $logger;
        $this->guzzleClient = new Client([
            'base_uri' => $this->apiUrl
        ]);
    }

    /**
     * @return string
     */
    public function connect(): string
    {
        $response = $this->guzzleClient->get('users/' . $this->userLdap, [
            'headers' => [
                'x-apikey' => $this->xApiKey,
                'Accept' => 'application/json'
            ]
        ]);

        return json_decode($response->getBody()->getContents())->token;
    }

    protected function getConnection(): string
    {
        if ($this->token === null) {
            $this->token = $this->connect();
        }

        return $this->token;
    }

    /**
     * Récupère les doctypes depuis un univers Jupiter
     *
     * @param string $univers
     *
     * @return array|null
     */
    public function getDoctypesByWorkspace(string $univers): ?array
    {
        $response = $this->queryWithToken("doctype/getdoctypebyworkspace?w=$univers");

        return $response;
    }

    /**
     * Récupère les metadatas d'un doctype
     *
     * @param string $doctypeId
     *
     * @return array|null
     */
    public function getMetadaByDoctype(string $doctypeId): ?array
    {
        $response = $this->queryWithToken("metadata/getmetadabydoctype?doctypeIds=$doctypeId");

        return $response === null ? null : $response['data'][0]['metadata'];
    }

    /**
     * @param string $username
     * @return array|null
     */
    public function getUser(string $username): ?array
    {
        return $this->queryWithToken("users/$username");
    }

    /**
     * Créer un nouvel utilisateur Jupiter
     *
     * @param string $username
     * @param        $profilId
     *
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createUser(string $username, string $profilId)
    {
        try {
            $response = $this->queryWithToken('users', 'POST', [
                'json' => [
                    'username'  => $username,
                    'profileId' => $profilId
                ]
            ]);

            if (is_null($response)) {
                return false;
            }

            return json_decode(json_encode($response), true);
        } catch (RequestException $e) {
            throw new NotFoundHttpException("Impossible de créer l'utilisateur dans JUPITER");
        }
    }

    /**
     * Récupère un profil depuis son ID
     *
     * @param string $profilId
     * @return array|null
     */
    public function getprofil(string $profilId): ?array
    {
        $response = $this->queryWithToken("profiles/$profilId");

        if ($response === null) {
            throw new NotFoundHttpException("Le profil n'existe pas sur Jupiter");
        }

        return $response;
    }

    /**
     * Récupère tous les profils utilisateur
     *
     * @return array|null
     */
    public function getProfilsUtilisateur(): ?array
    {
        return $this->queryWithToken('profiles');
    }

    /**
     * Récupère un profil jupiter à partir de son slug
     *
     * @param string $profilSlug
     * @return mixed
     */
    public function getProfilBySlug(string $profilSlug)
    {
        $profils = $this->getProfilsUtilisateur();

        foreach ($profils as $profil) {
            if ($profil['displayName'] === $profilSlug) {
                return $profil;
            }
        }

        throw new NotFoundHttpException("Le profil '$profilSlug' n'existe pas sur Jupiter");
    }

    /**
     * Permet d'ajouter un utilisateur à un profil Jupiter
     *
     * @param string $profilId
     * @param string $username
     * @param string|null $prenom
     * @param string|null $nom
     * @param string|null $email
     * @return mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function addUserToProfil(string $profilId, string $username, string $prenom = null, string $nom = null, string $email = null)
    {
        $user = $this->getUser($username);

        if ($user === null) {
            throw new NotFoundHttpException("L'utilisateur '$username' n'existe pas sur Jupiter");
        }
        // on supprime l'utilisateur au cas ou afin d'eviter les doublons
        $this->removeUserFromProfil($username, $profilId);
        $profil = $this->getProfil($profilId);

        $newUser = [
            'userName' => $username,
            'fullName' => trim(($prenom ?? '') . ' ' . ($nom ?? '')),
            'firstname' => $prenom ?? '',
            'lastname' => $nom ?? '',
            'email' => $email ?? '',
        ];

        $profil['users'][] = $newUser;

        return $this->updateProfil($profilId, $profil['users']);
    }

    /**
     * Met à jour un profil Jupiter
     *
     * @noinspection PhpDocSignatureInspection
     *
     * @param string                       $profilId
     * @param array|null                   $users
     * @param string|null                  $displayName
     * @param object|null                  $jupiterRight
     *
     * @return mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateProfil(string $profilId, array $users = null, string $displayName = null, object $jupiterRight = null)
    {
        $parameters = (array) $this->getProfil($profilId);

        if ($users !== null) {
            $parameters['users'] = $users;
        }
        if ($displayName !== null) {
            $parameters['displayName'] = $displayName;
        }
        if ($jupiterRight !== null) {
            $parameters['jupiterRight'] = $jupiterRight;
        }

        return $this->queryWithToken("profiles/$profilId", 'PUT', [
            'json' => $parameters
        ]);
    }

    /**
     * Supprime un utilisateur d'un profil Jupiter
     *
     * @param string $username
     * @param string $profilId
     * @return mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function removeUserFromProfil(string $username, string $profilId)
    {

        $profil = $this->getProfil($profilId);
        /** @noinspection PhpUndefinedFieldInspection */
        $profil['users'] = array_filter($profil['users'], function ($user) use ($username) {
            return $user['userName'] !== $username;
        });

        return $this->updateProfil($profilId, $profil['users']);
    }

    /**
     * Fonction permettant de requêter sur la GED et qui s'occupera de la récupération du token
     * pour le concaténer à l'URL de la ressource appelée.
     *
     * @param string $resourceUri
     * @param string $method
     * @param array  $params
     * @param bool   $withApiKey
     *
     * @return array|null
     */
    public function queryWithToken(string $resourceUri, string $method = 'GET', array $params = [], bool $withApiKey = false): ?array
    {
        if (strpos($resourceUri, '?') !== false) {
            $resourceUri .= '&';
        } else {
            $resourceUri .= '?';
        }

        try {
            $params = array_replace_recursive(['headers' => [
                'Accept' => 'application/json'
            ]], $params);

            if ($withApiKey === true) {
                $params['headers']['x-apikey'] = $this->xApiKey;
            }

            $response = $this->guzzleClient->request(
                $method,
                $resourceUri . 'token=' . $this->getConnection(),
                $params
            );

            $satusCode = $response->getStatusCode();
            if ($satusCode >= 200 && $satusCode < 300) {
                $str = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
                    return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
                }, $response->getBody()->getContents());

                $str = preg_replace('/[\x00]/', '', $str);

                return json_decode($str, true);
            } else {
                $this->logger->error(
                    'Erreur le code de retour est' . $satusCode,
                    [
                        'uri_resource' => $resourceUri,
                        'method' => $method,
                        'params' => $params
                    ]);

                return null;
            }
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Une erreur est survenue.',
                [
                    'uri_resource' => $resourceUri,
                    'method' => $method,
                    'params' => $params
                ]);

            return null;
        }
    }

    /**
     * Permet l'envoie d'un document de manière cryptée
     *
     * @param string      $fileOrFilePath
     * @param string      $univers
     * @param array       $metadatas
     * @param string      $typeDocument
     * @param string      $mimetype
     * @param string      $extension
     * @param boolean     $deleteFileAfter
     * @param string|null $customFilename
     *
     * @return array|null
     * @throws Exception
     */
    public function postDocument(
        string $fileOrFilePath,
        string $univers,
        array $metadatas,
        string $typeDocument,
        string $mimetype = 'application/pdf',
        string $extension = 'pdf',
        bool $deleteFileAfter = true,
        ?string $customFilename = null
    ): ?array {
        $data = [
            'doctype' => $typeDocument,
            'workspace' => $univers,
            'status' => self::DOCUMENT_STATUT_ACTIVE,
            'mimeType' => $mimetype,
            'extension' => $extension,
            'metadata' => $metadatas,
        ];

        if (!is_null($customFilename)) {
            $filename = $customFilename;
        } elseif (@file_exists($fileOrFilePath)) {
            $explodedPath = explode(DIRECTORY_SEPARATOR, $fileOrFilePath);
            $filename = $explodedPath[count($explodedPath)-1];
        } else {
            throw new Exception("Aucun nom de fichier n'a été fourni");
        }

        $fileContent = @file_exists($fileOrFilePath) ? file_get_contents($fileOrFilePath) : $fileOrFilePath;

        $params = [
            'multipart' => [
                [
                    'name' => 'uploadFile',
                    'contents' => $fileContent,
                    'filename' => pathinfo($filename, PATHINFO_FILENAME),
                ],
                [
                    'name' => 'data',
                    'contents' => json_encode($data, true),
                ]
            ]
        ];

        $response = $this->queryWithToken('document/quick-insert', 'POST', $params);

        if ($response !== null) {
            if ($deleteFileAfter === true && @file_exists($fileOrFilePath)) {
                unlink($fileOrFilePath);
            }

            return json_decode(json_encode($response), true);
        }

        if (!@file_exists($fileOrFilePath)) {
            $fileOrFilePath = '[CONTENU DU FICHIER]';
        }

        $this->logger->error(
            "Une erreur est survenue lors de l'envoi du document",
            [
                'cheminOuFichier' => $fileOrFilePath,
                'univers' => $univers,
                'metadatas' => $metadatas,
                'doctype' => $typeDocument,
                'mimetype' => $mimetype,
                'extension' => $extension
            ]);

        return null;
    }

    /**
     * @param string $documentId
     * @return string
     */
    public function getDocumentUrl(string $documentId): string
    {
        return $this->apiUrl . "version/downloadVersion?documentId=$documentId&token=" . $this->getConnection();
    }

    /**
     * Permet de télécharger un document décrypté
     *
     * @param string $documentId
     *
     * @return array
     * @throws GuzzleException
     */
    public function downloadDocument(string $documentId): array
    {
        $fichierResponse = $this->guzzleClient->request('GET', "version/downloadVersion?documentId=$documentId&token=" . $this->getConnection());

        // Recupération du nom original du fichier
        preg_match('/\s*filename\s?=\s?(.*)/', $fichierResponse->getHeader("Content-Disposition")[0], $output_array);
        $decryptedFileName = str_replace('"', '', $output_array[1]);

        $decryptedFileContent = [
            'fileName' => $decryptedFileName,
            'fileContent' => $fichierResponse->getBody()->getContents(),
        ];

        $this->logger->info(
            "Le document ayant pour ID $documentId à été téléchargé.",
            []);

        return $decryptedFileContent;
    }

    /**
     * Permet de télécharger un ZIP de plusieurs documents via un tableau de valeurs d'une metadonnée.
     *
     * @param string $metadata
     * @param array $documentIds
     * @return array
     */
    public function downloadFromMetadata(string $metadata, array $documentIds): array
    {
        $fichierResponse = $this->queryWithToken(
            "version/downloadFromMetadata",
            'POST',
            [
                'body' => json_encode([
                    'systemName' => $metadata,
                    'ids' => $documentIds
                ]),
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ],
            true);

        // Recupération du nom original du fichier
        preg_match('/\s*filename\s?=\s?(.*)/', $fichierResponse->getHeader("Content-Disposition")[0], $output_array);
        $decryptedFileName = str_replace('"', '', $output_array[1]);

        return [
            'fileName' => $decryptedFileName,
            'fileContent' => $fichierResponse->getBody()->getContents(),
        ];
    }

    /**
     * Permet de récupérer l'ensemble des documents présents dans un doctype
     *
     * @param string $univers
     * @param array $metadatas
     * @param bool $withMedatas
     * @return array
     */
    public function getDocuments(string $univers, array $metadatas = [], bool $withMedatas = false): array
    {
        $documents = [];

        $query = '';
        if (count($metadatas) !== 0) {
            $tempMetadatasArray = [];

            foreach ($metadatas as $metadataKey => $metadataValue) {
                //si c'est un array de values, on chaine avec des "or"
                // /!\ si c'est le cas, on peux peux pas avoir plusieurs metadata differente car la GED ne gere qu'un type
                // de condition (soit "or" soit "and"
                if (is_array($metadataValue)) {
                    $req = $metadataKey . ' eq "';

                    foreach ($metadataValue as $value) {
                        $req .= $value.'"';

                        if (end($metadataValue) != $value) {
                            $req .= " or " . $metadataKey . ' eq "';
                        }
                    }

                    $tempMetadatasArray[] = $req;
                } else {
                    $tempMetadatasArray[] = $metadataKey . ' eq "' . $metadataValue . '"';
                }
            }

            if (!empty($tempMetadatasArray)) {
                $query .= '&q=' . implode(' and ', $tempMetadatasArray);
            }
        }

        $uri = "document/tree?w=$univers";

        if ($withMedatas === true) {
            $uri .= '&withMetadata';
        }

        $uri.=$query;

        $treeResponse = $this->queryWithToken($uri, 'GET');

        //trie des documents par doctypes
        $docTypesByIds = [];
        foreach ($treeResponse['data'] as $item) {
            if ($item['type'] === 'dir') {
                $docTypesByIds[$item['id']] = $item['text'];
                $documents[$item['text']] = [];
            } else {
                $documents[$docTypesByIds[$item['parent']]][] = $item;
            }
        }

        return $documents;
    }


    /**
     * @param string $documentId
     */
    public function deleteDocument(string $documentId)
    {
        $response = $this->queryWithToken("documents/$documentId", 'DELETE');
    }


    /**
     * @param array  $metadatas
     * @param string $dateExpiration
     */
    public function archiverDocument(array $metadatas, string $dateExpiration)
    {
        $response = $this->queryWithToken(
            'document/archive',
            'POST',
            [
                'body' => json_encode(["metadatas" => $metadatas, "expires" => $dateExpiration]),
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ],
            true
        );

        $this->logger->notice(
            "Statut de l'archivage des documents",
            [
                'metadatas' => $metadatas,
                'dateExpiration' => $dateExpiration,
                'response' => $response
            ]
        );
    }
}
