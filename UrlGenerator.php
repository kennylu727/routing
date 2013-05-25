<?php namespace Illuminate\Routing;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection as SymfonyCollection;
use Symfony\Component\Routing\Generator\UrlGenerator as SymfonyGenerator;

class UrlGenerator {

	/**
	 * The route collection.
	 *
	 * @var \Symfony\Component\Routing\RouteCollection
	 */
	protected $routes;

	/**
	 * The request instance.
	 *
	 * @var \Symfony\Component\HttpFoundation\Request
	 */
	protected $request;

	/**
	 * The Symfony routing URL generator.
	 *
	 * @var \Symfony\Component\Routing\Generator\UrlGenerator
	 */
	protected $generator;

	/**
	 * Create a new URL Generator instance.
	 *
	 * @param  \Symfony\Component\Routing\RouteCollection  $routes
	 * @param  \Symfony\Component\HttpFoundation\Request   $request
	 * @return void
	 */
	public function __construct(SymfonyCollection $routes, Request $request)
	{
		$this->routes = $routes;

		$this->setRequest($request);
	}

	/**
	 * Get the full URL for the current request.
	 *
	 * @return string
	 */
	public function full()
	{
		return $this->request->fullUrl();
	}

	/**
	 * Get the current URL for the request.
	 *
	 * @return string
	 */
	public function current()
	{
		return $this->to($this->request->getPathInfo());
	}

	/**
	 * Get the URL for the previous request.
	 *
	 * @return string
	 */
	public function previous()
	{
		return $this->to($this->request->headers->get('referer'));
	}

	/**
	 * Generate a absolute URL to the given path.
	 *
	 * @param  string  $path
	 * @param  mixed   $parameters
	 * @param  bool    $secure
	 * @return string
	 */
	public function to($path, $parameters = array(), $secure = null)
	{
		if ($this->isValidUrl($path)) return $path;

		$scheme = $this->getScheme($secure);

		// Once we have the scheme we will compile the "tail" by collapsing the values
		// into a single string delimited by slashes. This just makes it convenient
		// for passing the array of parameters to this URL as a list of segments.
		$tail = implode('/', (array) $parameters);

		$root = $this->getRootUrl($scheme);

		return trim($root.'/'.trim($path.'/'.$tail, '/'), '/');
	}

	/**
	 * Generate a secure, absolute URL to the given path.
	 *
	 * @param  string  $path
	 * @param  array   $parameters
	 * @return string
	 */
	public function secure($path, $parameters = array())
	{
		return $this->to($path, $parameters, true);
	}

	/**
	 * Generate a URL to an application asset.
	 *
	 * @param  string  $path
	 * @param  bool    $secure
	 * @return string
	 */
	public function asset($path, $secure = null)
	{
		if ($this->isValidUrl($path)) return $path;

		// Once we get the root URL, we will check to see if it contains an index.php
		// file in the paths. If it does, we will remove it since it is not needed
		// for asset paths, but only for routes to endpoints in the application.
		$root = $this->getRootUrl($this->getScheme($secure));

		return $this->removeIndex($root).'/'.trim($path, '/');
	}

	/**
	 * Remove the index.php file from a path.
	 *
	 * @param  string  $root
	 * @return string
	 */
	protected function removeIndex($root)
	{
		$i = 'index.php';

		return str_contains($root, $i) ? str_replace('/'.$i, '', $root) : $root;
	}

	/**
	 * Generate a URL to a secure asset.
	 *
	 * @param  string  $path
	 * @return string
	 */
	public function secureAsset($path)
	{
		return $this->asset($path, true);
	}

	/**
	 * Get the scheme for a raw URL.
	 *
	 * @param  bool    $secure
	 * @return string
	 */
	protected function getScheme($secure)
	{
		if (is_null($secure))
		{
			return $this->request->getScheme().'://';
		}
		else
		{
			return $secure ? 'https://' : 'http://';
		}
	}

	/**
	 * Get the URL to a named route.
	 *
	 * @param  string  $name
	 * @param  mixed   $parameters
	 * @param  bool    $absolute
	 * @return string
	 */
	public function route($name, $parameters = array(), $absolute = true)
	{
		$route = $this->routes->get($name);

		$parameters = (array) $parameters;

		if (isset($route) and $this->usingQuickParameters($parameters))
		{
			$parameters = $this->buildParameterList($route, $parameters);
		}

		return $this->generator->generate($name, $parameters, $absolute);
	}

	/**
	 * Determine if we're short circuiting the parameter list.
	 *
	 * @param  array  $parameters
	 * @return bool
	 */
	protected function usingQuickParameters(array $parameters)
	{
		return count($parameters) > 0 and is_numeric(head(array_keys($parameters)));
	}

	/**
	 * Build the parameter list for short circuit parameters.
	 *
	 * @param  \Illuminate\Routing\Route  $route
	 * @param  array  $params
	 * @return array
	 */
	protected function buildParameterList($route, array $params)
	{
		$keys = $route->getParameterKeys();

		// If the number of keys is less than the number of parameters on a route
		// we'll fill out the parameter arrays with empty bindings on the rest
		// of the spots until they are equal so we can run an array combine.
		if (count($params) < count($keys))
		{
			$difference = count($keys) - count($params);

			$params += array_fill(count($params), $difference, null);
		}

		return array_combine($keys, $params);
	}

	/**
	 * Get the URL to a controller action.
	 *
	 * @param  string  $action
	 * @param  mixed   $parameters
	 * @param  bool    $absolute
	 * @return string
	 */
	public function action($action, $parameters = array(), $absolute = true)
	{
		// If haven't already mapped this action to a URI yet, we will need to spin
		// through all of the routes looking for routes that routes to the given
		// controller's action, then we will cache them off and build the URL.
		if ($name = $this->routes->getMapped($action))
		{
			return $this->route($name, $parameters, $absolute);
		}

		// If the action is not mapped explicitly, we will try to recreate this URL
		// using just conventions by getting the base URL that is registered for
		// the controller, and then use this regular "to" method to finish up.
		if ($base = $this->routes->getMappedBase($action))
		{
			return $this->wildcardAction($base, $parameters);
		}

		throw new InvalidArgumentException("Unknown action [$action].");
	}

	/**
	 * Get the URL to a wildcard controller action.
	 *
	 * @param  array   $base
	 * @param  array   $parameters
	 * @return string
	 */
	protected function wildcardAction(array $base, $parameters)
	{
		if (is_null($base['domain'])) return $this->to($base['uri'], $parameters);

		return $this->to($this->formatDomainAction($base, $parameters));
	}

	/**
	 * Format a domained wildcard action URI.
	 *
	 * @param  array   $base
	 * @param  array   $parameters
	 * @return string
	 */
	protected function formatDomainAction(array $base, $parameters)
	{
		$url = $this->getDomainBase($base['domain'], $base['uri']);

		// If the action is not mapped explicitly, we will try to recreate this URL
		// using just conventions by getting the base URL that is registered for
		// the controller, and then use this regular "to" method to finish up.
		$url = $this->replaceDomainWilds($url, $parameters);

		return trim($url.'/'.implode('/', $parameters), '/');
	}

	/**
	 * Get the base URL for a domain wildcard controller.
	 *
	 * @param  string  $domain
	 * @param  string  $uri
	 * @return string
	 */
	protected function getDomainBase($domain, $uri)
	{
		$port = $this->getPort() == '80' ? '' : ':'.$this->getPort();

		return $this->request->getScheme().'://'.$domain.$port.'/'.$uri;
	}

	/**
	 * Replace the domain wildcards on the given URL string.
	 *
	 * @param  string  $url
	 * @param  array   $parameters
	 * @return string
	 */
	protected function replaceDomainWilds($url, &$parameters)
	{
		// We will make the replacements for parameters on any wildcards that are in
		// a segment wrapper. When we actually make a replacement, we will remove
		// the parameter from the parameter array so it isn't added on endings.
		foreach ($parameters as $key => $value)
		{
			$url = preg_replace('/\{(.*)\}/', $value, $url, -1, $count);

			if ($count > 0) unset($parameters[$key]);
		}

		return $url;
	}

	/**
	 * Get the base URL for the request.
	 *
	 * @param  string  $scheme
	 * @return string
	 */
	protected function getRootUrl($scheme)
	{
		$root = $this->request->root();

		$start = starts_with($root, 'http://') ? 'http://' : 'https://';

		return preg_replace('~'.$start.'~', $scheme, $root, 1);
	}

	/**
	 * Determine if the given path is a valid URL.
	 *
	 * @param  string  $path
	 * @return bool
	 */
	public function isValidUrl($path)
	{
		if (starts_with($path, array('#', '//'))) return true;

		return filter_var($path, FILTER_VALIDATE_URL) !== false;
	}

	/**
	 * Get the port from the current request instance.
	 *
	 * @return string
	 */
	public function getPort()
	{
		return $this->request->getPort();
	}

	/**
	 * Get the request instance.
	 *
	 * @return \Symfony\Component\HttpFoundation\Request
	 */
	public function getRequest()
	{
		return $this->request;
	}

	/**
	 * Set the current request instance.
	 *
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @return void
	 */
	public function setRequest(Request $request)
	{
		$this->request = $request;

		$context = new RequestContext;

		$context->fromRequest($this->request);

		$this->generator = new SymfonyGenerator($this->routes, $context);
	}

	/**
	 * Get the Symfony URL generator instance.
	 *
	 * @return \Symfony\Component\Routing\Generator\UrlGenerator
	 */
	public function getGenerator()
	{
		return $this->generator;
	}

	/**
	 * Set the Symfony URL generator instance.
	 *
	 * @param  \Symfony\Component\Routing\Generator\UrlGenerator  $generator
	 * @return void
	 */
	public function setGenerator(SymfonyGenerator $generator)
	{
		$this->generator = $generator;
	}

}
