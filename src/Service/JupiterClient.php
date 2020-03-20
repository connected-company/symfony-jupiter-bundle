<?php

namespace Connected\JupiterBundle\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class JupiterClient
{
    /**
     * @var string|null
     */
    protected $token = null;

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
        $this->userLdap = $tokenStorage->getToken() !== null ? strtolower($tokenStorage->getToken()->getUsername()) : 'testinfo';
        $this->logger = $logger;
        $this->guzzleClient = new Client([
            'base_uri' => $apiUrl
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
        $wantedDoctype = false;

        $doctypesByWorkspace = $this->getDoctypesByWorkspace($univers);

        foreach ($doctypesByWorkspace['data'] as $doctype) {
            if ($doctype['displayName'] === $typeDocument) {
                $wantedDoctype = $doctype;
            }
        }

        if (!$wantedDoctype) {
            $this->logger->error("Impossible de trouver le doctype $typeDocument", []);

            throw new Exception(sprintf('Impossible de trouver le doctype %s', $typeDocument));
        }

        $completedMetadatas = [];
        $metadataByDoctype = $this->getMetadaByDoctype($wantedDoctype['id']);

        foreach ($metadataByDoctype as $data) {
            if (in_array($data['systemName'], array_keys($metadatas))) {
                $metaInfos = $metadatas[$data['systemName']];
                $value = $metaInfos["value"];
                $label = $metaInfos["label"];
                $completedMetadatas[] = [
                    'id' => $data["id"],
                    'type' => $data["type"],
                    'systemName' => $data["systemName"],
                    'value' => $value,
                    'label' => $label,
                    'linked' => false
                ];
            }
        }

        if (count($completedMetadatas) === 0) {
            $this->logger->error(
                'Impossible de trouver les métadonnées demandées',
                ['metadatas' => $metadatas]);

            throw new Exception('Impossible de trouver les métadonnées demandées');
        }

        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('', false) . '.' . $extension;

        $fileContent = @file_exists( $fileOrFilePath ) ? file_get_contents( $fileOrFilePath ) : $fileOrFilePath;

        $fileResource = fopen($tempFilePath, 'w+');
        fwrite($fileResource, $fileContent);

        if (!is_null($customFilename)) {
            $filename = $customFilename;
        } elseif (@file_exists($fileOrFilePath)) {
            $explodedPath = explode(DIRECTORY_SEPARATOR, $fileOrFilePath);
            $filename = $explodedPath[count($explodedPath)-1];
        } else {
            throw new Exception("Aucun nom de fichier n'a été fourni");
        }

        $params = [
            'multipart' => [
                [
                    'name' => 'uploadFile',
                    'contents' => $fileResource,
                    'filename' => $filename
                ]
            ]
        ];

        $response = $this->queryWithToken('file/upload', 'POST', $params);

        if ($response !== null) {
            $files = $response['files'];

            $postData = [
                'title' => substr($files['name'], 0, strrpos($files['name'], '.')),
                'mimeType' => $mimetype,
                'ext' => $extension,
                'docTypeId' => $wantedDoctype['id'],
                'url' => $files['url'],
                'status' => 'active',
                'metadata' => $completedMetadatas
            ];

            $response = $this->queryWithToken('documents',
                'POST',
                [
                    'body' => json_encode($postData),
                    'headers' => [
                        'Content-Type' => 'application/json'
                    ]
                ]
            );

            unlink($tempFilePath);

            if ($deleteFileAfter === true && @file_exists($fileOrFilePath)) {
                unlink($fileOrFilePath);
            }

            if (is_null($response)) {
                if (!@file_exists($fileOrFilePath)) {
                    $fileOrFilePath = '[CONTENU DU FICHIER]';
                }

                $this->logger->error(
                    'Une erreur est survenue lors de la création du document',
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

            if (!@file_exists($fileOrFilePath)) {
                $fileOrFilePath = '[CONTENU DU FICHIER]';
            }

            $this->logger->info(
                'Le document a bien été envoyé.',
                [
                    'cheminOuFichier' => $fileOrFilePath,
                    'univers' => $univers,
                    'metadatas' => $metadatas,
                    'doctype' => $typeDocument,
                    'mimetype' => $mimetype,
                    'extension' => $extension
                ]);

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
