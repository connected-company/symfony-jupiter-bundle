# jupiter-client
Client jupiter pour communiquer avec la GED

## installation
```bash
$ composer require connected-company/symfony-jupiter-bundle
```
Si vous utilsiez symfony Flex, le bundle sera automatiquement inclu.
Sinon, ajoutez la ligne suivante aux bundles qui sont chargés dans config/bundles.php :
```php
return [
    // ...
    Connected\JupiterBundle\JupiterBundle::class => ['all' => true],
];
```

Créez ensuite un service qui etendra celui du bundle (c'est dans ce service que vous pourrez ajouter vos constantes ou méthodes custom)
```php
<?php

namespace App\Service;

use Connected\JupiterBundle\Service\JupiterClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class JupiterClientService extends JupiterClient
{

    public function __construct(string $apiUrl, string $xApiKey, TokenStorageInterface $tokenStorage, LoggerInterface $logger)
    {
        parent::__construct($apiUrl, $xApiKey, $tokenStorage, $logger);
    }

}
```

## configuration
Vous devez créer un fichier `jupiter.yaml` dans `config/parameters` avec ceci

```yaml
    parameters:
        jupiter_api_url: "%env(resolve:JUPITER_URL)%"
        jupiter_x_api_key: "%env(resolve:JUPITER_X_API_KEY)%"
    
    services:
        App\Service\JupiterClientService:
            public: true
            autowire: true
            arguments:
                $apiUrl: '%jupiter_api_url%'
                $xApiKey: '%jupiter_x_api_key%'

```
Enfin, n'oubliez pas d'ajouter les variables suivantes à vos fichiers d'environnement
```yaml
JUPITER_URL=http://urlDeJupiter:8080/api/
JUPITER_X_API_KEY=VotreCle
```