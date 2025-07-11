<?php

namespace Nimbus\Controller;

/**
 * ControllerInterface defines the contract for all Nimbus controllers
 */
interface ControllerInterface
{
    /**
     * Handle GET requests
     * @param mixed ...$params Route parameters
     * @return mixed
     */
    public function get(...$params);
    
    /**
     * Handle POST requests
     * @param mixed ...$params Route parameters
     * @return mixed
     */
    public function post(...$params);
    
    /**
     * Handle PUT requests
     * @param mixed ...$params Route parameters
     * @return mixed
     */
    public function put(...$params);
    
    /**
     * Handle DELETE requests
     * @param mixed ...$params Route parameters
     * @return mixed
     */
    public function delete(...$params);
    
    /**
     * Handle PATCH requests
     * @param mixed ...$params Route parameters
     * @return mixed
     */
    public function patch(...$params);
}