<?php

namespace Connected\JupiterBundle\Service;

use Exception;

interface JupiterClientInterface
{
    /**
     * @return string
     */
    public function connect(): string;

    /**
     * Récupère les doctypes depuis un univers Jupiter
     *
     * @param string $univers
     *
     * @return array|null
     */
    public function getDoctypesByWorkspace(string $univers): ?array;

    /**
     * Récupère les metadatas d'un doctype
     *
     * @param string $doctypeId
     *
     * @return array|null
     */
    public function getMetadaByDoctype(string $doctypeId): ?array;

    /**
     * @param string $username
     * @return array|null
     */
    public function getUser(string $username): ?array;

    /**
     * Créer un nouvel utilisateur Jupiter
     *
     * @param string $username
     * @param        $profilId
     *
     * @return bool|mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createUser(string $username, string $profilId);

    /**
     * Récupère un profil depuis son ID
     *
     * @param string $profilId
     * @return array|null
     */
    public function getprofil(string $profilId): ?array;

    /**
     * Récupère tous les profils utilisateur
     *
     * @return array|null
     */
    public function getProfilsUtilisateur(): ?array;

    /**
     * Récupère un profil jupiter à partir de son slug
     *
     * @param string $profilSlug
     * @return mixed
     */
    public function getProfilBySlug(string $profilSlug);

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
    public function addUserToProfil(string $profilId, string $username, string $prenom = null, string $nom = null, string $email = null);

    /**
     * Met à jour un profil Jupiter
     *
     * @noinspection PhpDocSignatureInspection
     *
     * @param string $profilId
     * @param array|null $users
     * @param string|null $displayName
     * @param object|null $jupiterRight
     *
     * @return mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateProfil(string $profilId, array $users = null, string $displayName = null, object $jupiterRight = null);

    /**
     * Supprime un utilisateur d'un profil Jupiter
     *
     * @param string $username
     * @param string $profilId
     * @return mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function removeUserFromProfil(string $username, string $profilId);

    /**
     * Fonction permettant de requêter sur la GED et qui s'occupera de la récupération du token
     * pour le concaténer à l'URL de la ressource appelée.
     *
     * @param string $resourceUri
     * @param string $method
     * @param array $params
     * @param bool $withApiKey
     *
     * @return array|null
     */
    public function queryWithToken(string $resourceUri, string $method = 'GET', array $params = [], bool $withApiKey = false): ?array;

    /**
     * Permet l'envoie d'un document de manière cryptée
     *
     * @param string $fileOrFilePath
     * @param string $univers
     * @param array $metadatas
     * @param string $typeDocument
     * @param string $mimetype
     * @param string $extension
     * @param boolean $deleteFileAfter
     * @param string|null $customFilename
     *
     * @return array|null
     * @throws Exception
     */
    public function postDocument(string $fileOrFilePath, string $univers, array $metadatas, string $typeDocument, string $mimetype = 'application/pdf', string $extension = 'pdf', bool $deleteFileAfter = true, ?string $customFilename = null): ?array;

    /**
     * Permet de récupérer l'url pour télécharger le document
     *
     * @param string $documentId
     * @return string
     */
    public function getDocumentUrl(string $documentId): string;

    /**
     * Permet de télécharger un document décrypté
     *
     * @param string $documentId
     *
     * @return array
     * @throws GuzzleException
     */
    public function downloadDocument(string $documentId): array;


    /**
     * Permet de télécharger un ZIP de plusieurs documents via un tableau de valeurs d'une metadonnée.
     *
     * @param string $metadata
     * @param array $values
     * @return array|null
     * @throws Exception
     */
    public function downloadFromMetadata(string $metadata, array $values): ?array;

    /**
     * Permet de récupérer l'ensemble des documents présents dans un doctype
     *
     * @param string $univers
     * @param array $metadatas
     * @param bool $withMedatas
     * @return array
     */
    public function getDocuments(string $univers, array $metadatas = [], bool $withMedatas = false): array;

    public function searchDocuments(string $univers, ?\DateTime $dateModificationFrom = null, ?\DateTime $dateModificationTo = null, $withDeleted = false): array;

    /**
     * @param string $documentId
     */
    public function deleteDocument(string $documentId);

    /**
     * @param array $metadatas
     * @param string $dateExpiration
     */
    public function archiverDocument(array $metadatas, string $dateExpiration);
}