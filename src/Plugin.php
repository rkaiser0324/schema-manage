<?php
declare(strict_types=1);

namespace SchemaManage;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Cake\Console\CommandCollection;

/**
 * Plugin for SchemaManage
 */
class Plugin extends BasePlugin
{
    public function console(\Cake\Console\CommandCollection $commands): \Cake\Console\CommandCollection
    {
        $commands = parent::console($commands);

        return $commands;
    }
}
