Service Discovery
=================

[![Latest Stable Version](https://poser.pugx.org/mildabre/service-discovery/v/stable)](https://packagist.org/packages/mildabre/service-discovery)
[![License](https://poser.pugx.org/mildabre/service-discovery/license)](https://packagist.org/packages/mildabre/service-discovery)

Attribute-based service discovery for Nette DI Container.

Simplify your application configuration by automatically discovering and registering services based on:
- Class type (parent class)
- Interface implementation  
- PHP 8 attributes

Installation
------------

```bash
composer require mildabre/service-discovery
```


Configuration
-------------

Register the extension in your `common.neon`:

```neon
extensions:
    discovery: Mildabre\ServiceDiscovery\DI\ServiceDiscoveryExtension
```


Usage
-----

### Discovery by Type

Define discovery rules in your  `service.neon`:

```neon
discovery:
    in:
        - %appDir%/Controls
        - %appDir%/Model
    
    type:
        - App\Controls\Abstract\AbstractControl       # base class
        - App\Model\Abstract\ModelInterface             # interface
```

All classes in `%appDir%/Controls` and `%appDir%/Model` matching these criteria will be automatically registered.

### Discovery by Attribute

#### #[Service] - Register as Anonymous or Named Service

```php
use Mildabre\ServiceDiscovery\Attributes\Service;

#[Service]
class UserRepository        # Registered as auto-generated service name
{}

#[Service('user.repository')]
class UserRepository        # Registered with custom name 'user.repository'
{}
```

#### #[EventListener] - Event Listener Service Registration

Works with `mildabre/event-dispatcher` package:

```php
use Mildabre\ServiceDiscovery\Attributes\EventListener;

#[EventListener]
class UserRegisteredListener
{
    public function handle(UserRegisteredEvent $event): void      #  handle event
    {}
}
```

Services with this attribute are automatically tagged with `event.listener` tag and added by `mildabre/event-dispatcher extension` to EventDispatcher.

#### #[Excluded] - Exclude from Discovery

```php
use Mildabre\ServiceDiscovery\Attributes\Excluded;
use App\Model\Abstract\ModelInterface;

#[Excluded]
class ShiftControl extends AbstractControl    # Not registered despite matching discovery rules
{
    public function __construct(
        private readonly $model ModelInterface,
    )
    {}
}
```
Class excluded from auto-registration can still be instantiated manually via constructor, without relying on setter injection.

Manually-registered services in services.neon are not affected by the attribute!


### Enable Inject mode

```neon
discovery:
    in:
        - %appDir%/Controls
        - %appDir%/Model
        
    enableInject:
        - App\Controls\Abstract\AbstractControl       # base class
        - App\Model\Abstract\ModelInterface             # interface
```


#### #[Autowire] - Disable Autowiring

```php
use Mildabre\ServiceDiscovery\Attributes\Service;
use Mildabre\ServiceDiscovery\Attributes\Autowire;

#[Service, Autowire(false)]     # registered but not autowired, must be referenced explicitly
class DebugService
{}
```


Migration from Search Section
-----------------------------

**Before:**

```neon
search:
    application:
        in: %appDir%
        implements: App\Core\Interfaces\AsService

    model:
        in: '%appDir%/Model'
        implements: App\Model\Abstract\ModelInterface   
    
    controls:
        in: '%appDir%/Controls'
        extends: App\Controls\Abstract\AbstractControl
    
decorator:
    App\Core\Interfaces\Injectable:
        inject: true
```


```php
use App\Core\Interfaces\AsService;
use App\Core\Interfaces\Injectable;
use App\Controls\Abstract\AbstractControl;
use Nette\Application\UI\Control;

class DebugService implements AsService                             # pseudo-interface
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
        - App\Model\Abstract\ModelInterface
        - App\Controls\Abstract\AbstractControl
        
    enableInject:
        - App\Model\Abstract\ModelInterface
        - App\Controls\Abstract\AbstractControl
```


```php
use App\Core\Interfaces\AsService;
use App\Core\Interfaces\Injectable;
use App\Controls\Abstract\AbstractControl;
use Nette\Application\UI\Control;

class DebugService                             # pseudo-interface removed
{}

class AbstractControl extends Control          # pseudo-interface removed
{}
```


Requirements
------------

- PHP 8.3 or higher
- Nette DI 3.1+
- Nette Schema 1.2+
- Nette RobotLoader 4.0+


License
-------

MIT License. See [LICENSE](LICENSE) file.
