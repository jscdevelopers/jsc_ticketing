<?php
declare(strict_types=1);
namespace App\Http;

use Closure;

/** A deliberately small HTTP router for the creditors API. */
final class Route
{
    public string $method;
    public string $pattern;
    public string $rawPath;
    public $handler;
    /** @var array<int, callable> */
    public array $middlewares = [];
    public string $name = '';

    public function __construct(string $method, string $pattern, string $rawPath, $handler)
    {
        $this->method = $method;
        $this->pattern = $pattern;
        $this->rawPath = $rawPath;
        $this->handler = $handler;
    }

    public function middleware(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}

final class Router
{
    private string $base;
    /** @var array<int, Route> */
    private array $routes = [];

    public function __construct(string $base = '')
    {
        $this->base = rtrim($base, '/');
    }

    public function group(string $prefix, Closure $callback): void
    {
        $previousBase = $this->base;
        $this->base .= rtrim($prefix, '/');
        $callback($this);
        $this->base = $previousBase;
    }

    public function add(string $method, string $path, $handler): Route
    {
        $rawPath = $this->base . $path;
        $route = new Route(strtoupper($method), $this->compile($rawPath), $rawPath, $handler);
        $this->routes[] = $route;
        return $route;
    }

    public function get(string $path, $handler): Route { return $this->add('GET', $path, $handler); }
    public function head(string $path, $handler): Route { return $this->add('HEAD', $path, $handler); }
    public function post(string $path, $handler): Route { return $this->add('POST', $path, $handler); }
    public function put(string $path, $handler): Route { return $this->add('PUT', $path, $handler); }
    public function patch(string $path, $handler): Route { return $this->add('PATCH', $path, $handler); }
    public function delete(string $path, $handler): Route { return $this->add('DELETE', $path, $handler); }

    public function dispatch(Request $request): Response
    {
        $matching = $this->matchingRoutes($request->path);
        if ($request->method === 'OPTIONS') {
            $allowed = $this->allowedMethods($matching);
            $response = Response::json(['allowed_methods' => $allowed], 200);
            if ($allowed !== []) {
                $response->headers['Allow'] = implode(', ', $allowed);
            }
            return $response;
        }

        foreach ($this->effectiveMethods($request->method) as $method) {
            foreach ($matching as $route) {
                $params = [];
                if ($route->method !== $method || !$this->matches($route->pattern, $request->path, $params)) {
                    continue;
                }

                $request->params = $params;
                $request->attributes['route'] = ['method' => $request->method, 'path' => $route->rawPath, 'name' => $route->name];
                $handler = $this->resolve($route->handler);
                $pipeline = array_reduce(
                    array_reverse($route->middlewares),
                    static fn ($next, $middleware) => static fn (Request $incoming) => $middleware($incoming, $next),
                    static fn (Request $incoming) => $handler($incoming)
                );
                $response = $this->toResponse($pipeline($request));
                return $request->method === 'HEAD' ? $this->withoutBody($response) : $response;
            }
        }

        if ($matching !== []) {
            $allowed = $this->allowedMethods($matching);
            $response = Response::json(['error' => 'Method not allowed.', 'allowed_methods' => $allowed], 405);
            $response->headers['Allow'] = implode(', ', $allowed);
            return $response;
        }

        return Response::json(['error' => 'Not found.', 'error_code' => 'route_not_found'], 404);
    }

    /** @return array<int, Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @return array<int, Route> */
    public function matchingRoutes(string $path): array
    {
        return array_values(array_filter($this->routes, function (Route $route) use ($path): bool {
            $params = [];
            return $this->matches($route->pattern, $path, $params);
        }));
    }

    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . rtrim((string)$regex, '/') . '/?$#';
    }

    /** @param array<string, string> $params */
    private function matches(string $pattern, string $path, array &$params): bool
    {
        if (!preg_match($pattern, $path, $matches)) {
            return false;
        }
        foreach ($matches as $key => $value) {
            if (!is_int($key)) {
                $params[(string)$key] = (string)$value;
            }
        }
        return true;
    }

    private function resolve($handler): callable
    {
        if (is_array($handler) && count($handler) === 2) {
            return [new $handler[0](), $handler[1]];
        }
        if (is_callable($handler)) {
            return $handler;
        }
        throw new \RuntimeException('Invalid route handler.');
    }

    /** @param array<int, Route> $routes @return array<int, string> */
    private function allowedMethods(array $routes): array
    {
        $methods = array_values(array_unique(array_map(static fn (Route $route): string => $route->method, $routes)));
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }
        if ($routes !== []) {
            $methods[] = 'OPTIONS';
        }
        $methods = array_values(array_unique($methods));
        $order = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        usort($methods, static fn (string $left, string $right): int => array_search($left, $order, true) <=> array_search($right, $order, true));
        return $methods;
    }

    /** @return array<int, string> */
    private function effectiveMethods(string $method): array
    {
        return match (strtoupper($method)) {
            'HEAD' => ['GET'],
            default => [strtoupper($method)],
        };
    }

    private function toResponse(mixed $result): Response
    {
        return $result instanceof Response ? $result : Response::json($result);
    }

    private function withoutBody(Response $response): Response
    {
        $head = new Response('', $response->status, $response->headers);
        $head->format = $response->format;
        $head->payload = $response->payload;
        return $head;
    }
}
