<?php
namespace MissCache\Util;

final class PluginRouter
{
    /** @var PluginInterface[] */
    private array $plugins = [];
    private string $base_path = '';

    public function __construct(string $base_path, array $plugins) {
        $this->base_path = rtrim($base_path, '/');
        foreach ($plugins as $p) {
            $this->register($p);
        }
    }

    public function register(PluginInterface $plugin): void {
        $this->plugins[$plugin->getRoutePrefix()] = $plugin;
    }

    public function dispatch(): bool
    {
        $req = CacheRequest::fromRouteRemainder($_SERVER['REQUEST_URI']);
        huhl($req);
        $prefix = $req->routePrefix();
        $plugin = $this->plugins[$prefix] ?? null;
        if (!$plugin) {
            throw new \RuntimeException("No plugin for route prefix: {$prefix}");
        }
        return $plugin->generate($req);
    }
}