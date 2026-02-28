Service Discovery
=================

[![Latest Stable Version](https://poser.pugx.org/mildabre/service-discovery/v/stable)](https://packagist.org/packages/mildabre/service-discovery)
[![License](https://poser.pugx.org/mildabre/service-discovery/license)](https://packagist.org/packages/mildabre/service-discovery)

Attribute-based service discovery for Nette DI Container.

Simplify your application configuration by automatically discovering and registering services based on:
- Class type (parent class)
- PHP 8 attributes

Installation
------------

```bash
composer require mildabre/service-discovery
```

Bootstrap Hook
--------------

Add this hook to your Nette `Bootstrap.php`:

```php
use Mildabre\ServiceDiscovery\DI\ServiceDiscoveryExtension;

public function bootWebApplication(): Container
{
    $this->initializeEnvironment();
    ServiceDiscoveryExtension::boot($this->rootDir.'/temp');    # add just before $configurator->createContainer()
    return $this->configurator->createContainer();
}  
```
Configuration
-------------

Register the extension in your `common.neon` file:

```neon
extensions:
    discovery: Mildabre\ServiceDiscovery\DI\ServiceDiscoveryExtension
```

Service Discovery by Class Type
-------------------------------

Define discovery rules in your  `service.neon`:

```neon
discovery:
    in:
        - %appDir%/Controls
        - %appDir%/Model
    
    type:
        - App\Controls\Abstract\AbstractControl
        - App\Model\Abstract\AbstractModel
```

All classes in `%appDir%/Controls` and `%appDir%/Model` matching these criteria will be automatically registered. Discovery by implementing interfaces is by design not possible.

Service Discovery by Attribute
------------------------------

```php
use Mildabre\ServiceDiscovery\Attributes\Service;

#[Service]
class TokenManager
{}

```

Event Listener Discovery by Attribute
-------------------------------------

Require [`mildabre/event-dispatcher`](https://github.com/mildabre/event-dispatcher).  package.

```php
use Mildabre\ServiceDiscovery\Attributes\EventListener;

#[EventListener]
class UserRegisteredListener
{
    public function handle(UserRegisteredEvent $event): void      #  handle event
    {}
}
```

Services with this attribute are automatically tagged with `event.listener` tag and added by `mildabre/event-dispatcher` to EventDispatcher.

Exclude Service from Discovery
------------------------------

```php
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use App\Model\Abstract\AbstractModel;

#[Excluded]                                     # overrides discovery by-type
class ShiftControl extends AbstractControl 
{
    public function __construct(
        private readonly AbstractModel $model,
    )
    {}
}
```
Class excluded from auto-registration can still be instantiated manually via constructor, without relying on setter injection.

Important: Manually-registered services in services.neon are not affected by the attribute!


Enable Inject mode by Class Type
--------------------------------

```neon
discovery:
    in:
        - %appDir%/Controls
        - %appDir%/Model
        
    enableInject:
        - App\Controls\Abstract\AbstractControl
        - App\Model\Abstract\AbstractModel
```
Enable Inject mode by implementing interfaces is by design not possible.


Migration from Search Section
-----------------------------

**Before:**

```neon
search:
    application:
        in: %appDir%
        implements: App\Core\Interfaces\AsService
    
    controls:
        in: '%appDir%/Controls'
        extends: App\Controls\Abstract\AbstractControl

decorator:
    App\Core\Interfaces\Injectable:
        inject: true
```


```php
use App\Core\DI\AsService;
use App\Core\DI\Injectable;

class TokenService implements AsService
{}

class AbstractControl extends Control implements Injectable
{}
```

**After:**

```neon
discovery:
    in:
        - %appDir%
        
    type:
        - App\Controls\Abstract\AbstractControl
        
    enableInject:
        - App\Controls\Abstract\AbstractControl
```


```php
use Mildabre\ServiceDiscovery\Attributes\Service;

#[Service]
class TokenService
{}
```

Go Event-Driven
---------------

Service Discovery is a natural foundation for event-driven architecture. Pair it with `mildabre/event-dispatcher` - simple EventDispatcher that brings modern architecture possibilities. 

- automatic listener discovery
- zero manual wiring
- clean separation of concerns

A worthy plugin for modern Nette-based applications.

[mildabre/event-dispatcher](https://github.com/mildabre/event-dispatcher)

Requirements
------------

- PHP 8.1 or higher
- Nette DI 3.1+
- Nette Schema 1.2+
- Nette RobotLoader 4.0+


License
-------

MIT License. See [LICENSE](LICENSE) file.
