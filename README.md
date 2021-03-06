# org.civicrm.flexmailer

The FlexMailer is an email delivery system for CiviCRM v4.7+ which supports
batching and events, such as `WalkBatchesEvent`, `ComposeBatchEvent` and
`SendBatchEvent`.

FlexMailer includes default listeners for these events.  The listeners behave
in basically the same way as CiviMail's traditional BAO-based delivery
system (respecting `mailerJobSize`, `mailThrottleTime`, `mailing_backend`,
`hook_civicrm_alterMailParams`, etal).  However, this arrangement allows you
change behaviors in more fine-grained ways.

> NOTE: Some examples have not been tested well. This is an early revision of the doc+code!

## Installation

```
cv dl --dev flexmailer
```

## Unit Tests

The headless unit tests are based on `phpunit` and `cv`. Simply run:

```
phpunit4
```

## Events: Inspection

To see what event listeners are configured, run:

```
cv debug:event-dispatcher /flexmail/
```

## Events: `ComposeBatchEvent`

The [`ComposeBatchEvent`](src/Event/ComposeBatchEvent.php) (`EVENT_COMPOSE`) builds the email messages.  Each message
is represented as a [`FlexMailerTask`](src/FlexMailerTask.php) with a list of [`MailParams`](src/MailParams.php).

Some listeners are "under the hood" -- they define less visible parts of the message, e.g.

 * `BasicHeaders`  defines `Message-Id`, `Precedence`, `From`, `Reply-To`, and others.
 * `BounceTracker` defines various headers for bounce-tracking.
 * `OpenTracker` appends an HTML tracking code to any HTML messages.

The heavy-lifting of composing the message content is also handled by a listener, such as
[`DefaultComposer`](src/Listener/DefaultComposer.php). `DefaultComposer` replicates
traditional CiviMail functionality:

 * Reads email content from `$mailing->body_text` and `$mailing->body_html`.
 * Interprets tokens like `{contact.display_name}` and `{mailing.viewUrl}`.
 * Loads data in batches.
 * Post-processes the message with Smarty (if `CIVICRM_SMARTY` is enabled).

The traditional CiviMail semantics have some problems -- e.g.  the Smarty post-processing is incompatible with Smarty's
template cache, and it is difficult to securely post-process the message with Smarty.  However, changing the behavior
would break existing templates.

A major goal of FlexMailer is to facilitate a migration toward different template semantics.  For example, an
extension might (naively) implement support for Mustache templates using:

```php
function mustache_civicrm_container($container) {
  $container->addResource(new \Symfony\Component\Config\Resource\FileResource(__FILE__));
  $container->findDefinition('dispatcher')->addMethodCall('addListener',
    array(\Civi\FlexMailer\FlexMailer::EVENT_COMPOSE, '_mustache_compose_batch')
  );
}

function _mustache_compose_batch(\Civi\FlexMailer\Event\ComposeBatchEvent $event) {
  if ($event->getMailing()->template_type !== 'mustache') return;

  $m = new Mustache_Engine();
  foreach ($event->getTasks() as $task) {
    if ($task->hasContent()) continue;
    $contact = civicrm_api3('Contact', 'getsingle', array(
      'id' => $task->getContactId(),
    ));
    $task->setMailParam('text', $m->render($event->getMailing()->body_text, $contact));
    $task->setMailParam('html', $m->render($event->getMailing()->body_html, $contact));
  }
}
```

This implementation is naive in a few ways -- it performs separate SQL queries for each recipient; it doesn't optimize
the template compilation; it has a very limited range of tokens; and it doesn't handle click-through tracking.  For
more ideas about these issues, review [`DefaultComposer`](src/Listener/DefaultComposer.php).

> FIXME: Core's `TokenProcessor` is useful for batch-loading token data.
> However, you currently have to use `addMessage()` and `render()` to kick it
> off -- but those are based on CiviMail template notation.  We should provide
> another function that doesn't depend on the template notation -- so that
> other templates can leverage our token library.

> **Tip**: When you register a listener for `EVENT_COMPOSE`, note the weight.
> The default weight puts your listener in the middle of pipeline -- right
> before the `DefaultComposer`.  However, you might want to position
> relative to other places -- e.g.  `WEIGHT_PREPARE`, `WEIGHT_MAIN`,
> `WEIGHT_ALTER`, or `WEIGHT_END`.

## Events: `SendBatchEvent`

The [`SendBatchEvent`](src/Event/SendBatchEvent.php) (`EVENT_SEND`) takes a
batch of recipients and messages, and it delivers the messages.  For
example, suppose you wanted to replace the built-in delivery mechanism with
a batch-oriented web-service:

```php
function example_civicrm_container($container) {
  $container->addResource(new \Symfony\Component\Config\Resource\FileResource(__FILE__));
  $container->findDefinition('dispatcher')->addMethodCall('addListener',
    array(\Civi\FlexMailer\FlexMailer::EVENT_SEND, '_example_send_batch')
  );
}

function _example_send_batch(\Civi\FlexMailer\Event\SendBatchEvent $event) {
  $event->stopPropagation(); // Disable standard delivery

  $context = stream_context_create(array(
    'http' => array(
      'method'  => 'POST',
      'header'  => 'Content-type: application/vnd.php.serialize',
      'content' => serialize($event->getTasks()),
    ),
  ));
  return file_get_contents('https://example.org/batch-delivery', false, $context);
}
```

## Events: `WalkBatchesEvent`

The [`WalkBatchesEvent`](src/Event/WalkBatchesEvent.php) (`EVENT_WALK`)
examines the recipient list and pulls out a subset for whom you want to send
email.  This is useful if you need strategies for chunking-out deliveries.

The basic formula for defining your own batch logic is:

```php
function example_civicrm_container($container) {
  $container->addResource(new \Symfony\Component\Config\Resource\FileResource(__FILE__));
  $container->findDefinition('dispatcher')->addMethodCall('addListener',
    array(\Civi\FlexMailer\FlexMailer::EVENT_WALK, '_example_walk_batches')
  );
}

function _example_walk_batches(\Civi\FlexMailer\Event\WalkBatchesEvent $event) {
  $event->stopPropagation(); // Disable standard delivery

  while (...) {
    $tasks = array();
    $task[] = new FlexMailerTask(...);
    $task[] = new FlexMailerTask(...);
    $task[] = new FlexMailerTask(...);
    $event->visit($tasks);
  }
}
```

## FAQ: How do you register a listener?

The examples above use `hook_civicrm_container` to manipulate the `dispatcher`;
and, specifically, they use `addListener()` to register a single global
function.  However, you can also add multievent "subscribers" via
`addSubscriber()`, and you can register object-oriented services via
`addListenerService()` or `addSubscriberService()`.

For more discussion, see http://symfony.com/doc/current/components/event_dispatcher/introduction.html

## FAQ: Why use Symfony `EventDispatcher` instead of `CRM_Utils_Hook`?

There are several advantages of Symfony's event system -- for example, it
supports type-hinting, better in-source documentation, better
object-oriented modeling, and better refactoring.  However, that's not why
FlexMailer uses it.  Firstly, Symfony allows you to have multiple listeners
in the same module.  Secondly, when you have multiple parties influencing an
event, the EventDispatcher allows you to both (a) set priorities among them
and (b) allow one listener to veto other listeners.

Some of these characteristics are also available in CMS event systems, but
not consistently so.  The challenge of `CRM_Utils_Hook` is that it must
support the lowest-common denominator of functionality.
