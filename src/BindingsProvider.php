<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Wszdb\FlarumAiChat\Agent\Action;

class BindingsProvider extends AbstractServiceProvider
{
    public function register()
    {
        // See https://docs.flarum.org/extend/service-provider.html#service-provider for more information.
    }

    public function boot(Container $container)
    {
        Action::setEventDispatcher($this->container->make(Dispatcher::class));

        // ponytail: Action::$agent is read nowhere, and building the agent here
        // cost a user lookup, a client build and a DNS resolution on every single
        // request, page views included. Jobs and listeners take the agent from the
        // container when they actually answer.
    }
}
