---
currentMenu: open-cloud
---

# OpenCloud

First, you will need to install the adapter:
```bash
composer require gaufrette/opencloud-adapter
```

To use the OpenCloud adapter you will need to create a connection using the
[OpenCloud SDK](https://github.com/php-opencloud/openstack).

```php
use Gaufrette\Adapter\OpenCloud as OpenCloudAdapter;
use Gaufrette\Filesystem;
use OpenStack\OpenStack;

$openstack = new OpenStack([
    'username' => 'your username',
    'password' => 'your Keystone password',
    'tenantName' => 'your tenant (project) name',
    'authUrl' => 'https://example.com/v2/identity',
]);

$adapter = new OpenCloudAdapter(
    $openstack->objectStoreV1(),
    'container-name',
    true // optional, indicates whether to create the container or not
         // if it does not exist, default to false
);

$filesystem = new Filesystem($adapter);
```

See [here](https://github.com/php-opencloud/openstack/blob/master/src/OpenStack.php)
for all OpenStack connection options.
