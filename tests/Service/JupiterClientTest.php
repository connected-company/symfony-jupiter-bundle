<?php

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Connected\JupiterBundle\Service\JupiterClient;

class JupiterClientTest extends WebTestCase
{
    const UNIVERS_ENSEMBLE = 'HERACLES_ENSEMBLE';
    private TokenStorageInterface $tokenStorage;
    private LoggerInterface $logger;
    private JupiterClient $jupiterClient;
    private \Symfony\Contracts\HttpClient\HttpClientInterface $client;

    protected function setUp(): void
    {
        $this->client = \Symfony\Component\HttpClient\HttpClient::create();
        self::bootKernel();
        $container = static::getContainer();

        $this->tokenStorage = $container->get(TokenStorageInterface::class);
        $this->logger = $container->get(LoggerInterface::class);
        $this->client = $this->client->withOptions(["verify_peer"=>false]);

        $this->jupiterClient = new JupiterClient(
            'https://jupiter-api.valeur-et-capital.qualif/api/',
            'U9TPNZFuWFpEVBTKVRCKF7xY',
            $this->tokenStorage,
            $this->logger,
            $this->client
        );
    }

    public function testConnect()
    {
        $token = $this->jupiterClient->connect();
        self::assertNotEmpty($token);
    }

    public function testGetDoctypesByWorkspace()
    {
        $response = $this->jupiterClient->getDoctypesByWorkspace(self::UNIVERS_ENSEMBLE);
        self::assertNotEmpty($response);
        self::assertArrayHasKey('status', $response);
        self::assertArrayHasKey('data', $response);
    }

    public function testPostDocument()
    {
        $uploadedFile = new UploadedFile(
            __DIR__ . '/../../tests/FileModels/example.pdf',
            'test.pdf',
            'application/zip',
            null,
            true
        );
        $response = $this->jupiterClient->postDocument(
            $uploadedFile,
            self::UNIVERS_ENSEMBLE,
            ['HERACLES_ENSEMBLE_ID' => ['value' => 774, 'label' => 774]],
            'PV assemblée générale'
        );
        self::assertNotEmpty($response);
        self::assertArrayHasKey('status', $response);
        self::assertArrayHasKey('data', $response);
    }

    public function testDownloadFromMetadata() {
        $response = $this->jupiterClient->downloadFromMetadata('HERACLES_FACTURE_ID', [297580]);
        self::assertNotEmpty($response);
    }

    public function testDownloadDocument() {
        $response = $this->jupiterClient->downloadDocument('645bab0be24b34ad97065491');
        self::assertNotEmpty($response);
    }
}