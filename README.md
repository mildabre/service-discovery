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

Bootstrap hook
--------------

Add to your `Bootstrap.php`:

```php
use Bite\DI\BiteExtension;
use Mildabre\ServiceDiscovery\DI\ServiceDiscoveryExtension;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

class Bootstrap
{
    private Configurator $configurator;
    private string $rootDir;

    public function __construct()
    {
        $this->rootDir = dirname(__DIR__);
        $this->configurator = new Configurator();
        $this->configurator->setTempDirectory($this->rootDir.'/temp');
    }

    public function bootWebApplication(): Container
    {
        $this->initializeEnvironment();

        ServiceDiscoveryExtension::boot($this->rootDir.'/temp');    # add this boot hook

        return $this->configurator->createContainer();
    }
    
    // .....
}
```
Configuration
-------------

Register the extension in your `common.neon`:

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
class TokenManager                # Registered with auto-generated service name
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
        private readonly $model AbstractModel,
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
        implements: App\Core\Interfaces\AsService       # pseudo-interface
    
    controls:
        in: '%appDir%/Controls'
        extends: App\Controls\Abstract\AbstractControl

    model:
        in: '%appDir%/Model'
        implements: App\Model\Abstract\AbstractModel   
    
decorator:
    App\Core\Interfaces\Injectable:                     # pseudo-interface
        inject: true
```


```php
use App\Core\Interfaces\AsService;
use App\Core\Interfaces\Injectable;
use App\Controls\Abstract\AbstractControl;
use Nette\Application\UI\Control;

class TokenService implements AsService                             # pseudo-interface
{}

class AbstractControl extends Control implements Injectable          # pseudo-interface
{}
```

**After:**

```neon
discovery:
    in:
        - %appDir%
        
    type:
        - App\Controls\Abstract\AbstractControl
        - App\Model\Abstract\AbstractModel
        
    enableInject:
        - App\Controls\Abstract\AbstractControl
        - App\Model\Abstract\AbstractModel
```


```php
use App\Core\Interfaces\AsService;
use App\Core\Interfaces\Injectable;
use App\Controls\Abstract\AbstractControl;
use Nette\Application\UI\Control;

class TokenService                             # no pseudo-interface
{}

class AbstractControl extends Control          # no pseudo-interface
{}
```

Go Event-Driven
------------

Service Discovery is a natural foundation for event-driven architecture. Pair it with `mildabre/event-dispatcher` — a fresh, modern EventDispatcher designed for PHP 8.4+ that brings new possibilities to well-architected applications. Automatic listener discovery, zero manual wiring, clean separation of concerns. A worthy companion for any modern PHP framework.

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
