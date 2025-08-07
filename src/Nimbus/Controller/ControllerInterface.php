<?php

declare(strict_types=1);

namespace Nimbus\Controller;

/**
 * ControllerInterface defines the contract for all Nimbus controllers
 * 
 * All controllers must implement HTTP method handlers for REST operations
 * 
 * @package Nimbus\Controller
 * @author Nimbus Framework
 * @license Apache-2.0
 * @copyright 2025 SmallCloud, LLC
 */
interface ControllerInterface
{
    /**
     * Handle GET requests
     * 
     * @param mixed ...$params Route parameters passed from the router
     * @return void
     */
    public function get(...$params): void;
    
    /**
     * Handle POST requests
     * 
     * @param mixed ...$params Route parameters passed from the router
     * @return void
     */
    public function post(...$params): void;
    
    /**
     * Handle PUT requests
     * 
     * @param mixed ...$params Route parameters passed from the router
     * @return void
     */
    public function put(...$params): void;
    
    /**
     * Handle DELETE requests
     * 
     * @param mixed ...$params Route parameters passed from the router
     * @return void
     */
    public function delete(...$params): void;
    
    /**
     * Handle PATCH requests
     * 
     * @param mixed ...$params Route parameters passed from the router
     * @return void
     */
    public function patch(...$params): void;
}