<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

// Micro-routeur : match (méthode, pattern) → handler.
// Pattern style "/mois/{mois}", le paramètre est passé au handler.

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($this->routes as [$m, $pattern, $handler]) {
            if ($m !== $method) {
                continue;
            }
            $regex = '#^' . preg_replace(
                '#\{([a-zA-Z0-9_]+)\}#',
                '(?P<$1>[^/]+)',
                $pattern
            ) . '$#';
            if (preg_match($regex, $path, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) {
                    if (!is_int($k)) {
                        $params[$k] = $v;
                    }
                }
                $handler($params);
                return;
            }
        }

        erreur(404, 'Page introuvable.');
    }
}
