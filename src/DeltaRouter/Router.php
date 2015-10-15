<?php
namespace DeltaRouter;

use DeltaRouter\Exception\NotFoundException;
use DeltaUtils\Object\Collection;
use HttpWarp\Request;
use HttpWarp\Url;

/**
 * класс осуществляет роутинг и вызывает нужные обработчики
 */
class Router
{
    const RUN_NEXT = "____run_next";

    /** @var array Collection|Router[] */
    protected $routes = [];
    protected $isRun = false;

    /**
     * @var Request
     */
    protected $request;

    function __construct(Request $request = null)
    {
        if (!is_null($request)) {
            $this->setRequest($request);
        }
        $this->routes = new Collection();
    }

    /**
     * @param Request $request
     */
    public function setRequest($request)
    {
        $this->request = $request;
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        if (is_null($this->request)) {
            $this->request = new Request();
        }

        return $this->request;
    }

    /**
     * @return Collection|Route[]
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * @param array $routes
     */
    public function setRoutes(array $routes)
    {
        foreach ($routes as $route) {
            if (!$route instanceof Route) {
                $route = new Route($route);
            }
            $this->routes[$route->getId()] = $route;
        }
    }

    public function getPatternsTree()
    {
        $routesTree = [];
        foreach ($this->getRoutes() as $route) {
            $methods = $route->getMethods();
            foreach ($methods as $method) {
                $routesTree[$method][$route->getType()][] = $route;
            }
        }
        //sort by types
        foreach ($routesTree as $method => &$types) {
            ksort($types);
            foreach ($types as $type => &$routes) {
                switch ($type) {
                    case RoutePattern::TYPE_FULL:
                    case RoutePattern::TYPE_FIRST_PREFIX :
                    case RoutePattern::TYPE_PREFIX: {
                        usort($routes, function ($a, $b) {
                            /** @var Route $a */
                            /** @var Route $b */
                            $la = $a->getMaxLength();
                            $lb = $b->getMaxLength();
                            if ($la === $lb) {
                                return 0;
                            }

                            return ($la > $lb) ? -1 : 1;
                        });
                        break;
                    }
                }
            }
        }

        return $routesTree;
    }

    public function isMatchByType($value, $pattern, $type = self::TYPE_FULL)
    {
        switch ($type) {
            case RoutePattern::TYPE_FULL :
                return (string)$value === (string)$pattern;
                break;
            case RoutePattern::TYPE_PREFIX:
            case RoutePattern::TYPE_FIRST_PREFIX:
                return strpos($value, $pattern) === 0;
                break;
            case RoutePattern::TYPE_REGEXP:
                $compare = preg_match($value, $pattern);
                if ($compare === false) {
                    throw new \InvalidArgumentException("Bad regexp");
                }
                return $compare;
                break;
            case RoutePattern::TYPE_PARAMS: {
                if (!$value instanceof Url\Query && !$pattern instanceof Url\Query) {
                    throw new \InvalidArgumentException("Value and pattern mast be type Query for pattern type " . RoutePattern::TYPE_PARAMS);
                }
                if ($pattern->count()> $value->count()){
                    return false;
                }
                $compare = false;
                foreach($pattern as $name=>$valueParam) {
                    if (!isset($value[$name]) || (string) $value[$name] !== (string) $valueParam) {
                        return false;
                    }
                    $compare = true;
                }
                return $compare;
            }
            default:
                throw new \InvalidArgumentException("This type compare not realised");
        }
    }

    public function isMatch(Route $route, Url $url)
    {
        $match = false;
        foreach ($route->getPatterns() as $pattern) {
            switch ($pattern->getPart()) {
                case RoutePattern::PART_DOMAIN:
                    $match = $this->isMatchByType($url->getHost(), $pattern->getValue(), $pattern->getType());
                    break;
                case RoutePattern::PART_PATH:
                    $match = $this->isMatchByType($url->getPath(), $pattern->getValue(), $pattern->getType());
                    break;
                case RoutePattern::PART_PARAM:
                    $urlValue = $url->getQuery();
                    $match = $this->isMatchByType($urlValue, $pattern->getValue(), $pattern->getType());
                    break;
                default:
                    throw new \InvalidArgumentException("This type compare by part not realised");
            }
            if ($match === false) {
                return false;
            }
        }

        return $match;
    }

    public function exec(Route $route)
    {
        return call_user_func($route->getAction());
    }

    public function run()
    {
        try {
            if ($this->getRoutes()->isEmpty()) {
                throw new \Exception("In this router urls is not defined");
            }

            if ($this->isRun) {
                return;
            } //fix double run
            $this->isRun = true;

            $methods = [Route::METHOD_ALL, $this->getRequest()->getMethod()];
            $currentUrl = $this->getRequest()->getUrl();
            $routes = $this->getPatternsTree();

            $workedMethods = array_intersect_key($routes, array_flip($methods));

            $processed = false;
            foreach ($workedMethods as $method => $types) {
                foreach ($types as $type => $routes) {
                    /** @var Route[] $routes */
                    foreach ($routes as $route) {
                        if ($this->isMatch($route, $currentUrl)) {
                            $runResult = $this->exec($route);
                            $processed = true;
                            if ($runResult !== self::RUN_NEXT) {
                                break;
                            }
                        }
                    }
                }
            }

            if (!$processed) {
                throw new NotFoundException();
            }
        } catch (NotFoundException $e) {
            return $this->exception404();
        }

    }

    function __invoke()
    {
        return $this->run();
    }

    public function exception404()
    {
        if (headers_sent()) {
            throw new \LogicException('Headers already send, url no found');
        }
        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        header("Status: 404 Not Found");
        $_SERVER['REDIRECT_STATUS'] = 404;

        echo "<h1>Not Found</h1>";
    }

}
