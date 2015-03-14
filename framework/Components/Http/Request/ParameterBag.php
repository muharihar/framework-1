<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Http\Request;

class ParameterBag
{
    /**
     * Associated parameters to read.
     *
     * @var array
     */
    protected $parameters;

    /**
     * Parameter bag used to perform read only operations with request attributes.
     *
     * @param array $parameters
     */
    public function __construct(array $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Check if property key exists.
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return array_key_exists($name, $this->parameters);
    }

    /**
     * Get property value.
     *
     * @param string $name    Property key.
     * @param mixed  $default Default value if key not exists.
     * @return mixed
     */
    public function get($name, $default = null)
    {
        if (!$this->has($name))
        {
            return $default;
        }

        return $this->parameters[$name];
    }

    /**
     * Get all property values.
     *
     * @return array
     */
    public function all()
    {
        return $this->parameters;
    }

    /**
     * Fetch only specified keys from property values.
     *
     * @param array $keys Keys to fetch from parameter values.
     * @param bool  $fill Fill missing key with filler value.
     * @param mixed $filler
     * @return array
     */
    public function fetch(array $keys, $fill = false, $filler = null)
    {
    }
}