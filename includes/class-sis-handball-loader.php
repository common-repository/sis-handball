<?php

/**
 * @link http://felixwelberg.de
 * @since 1.0.0
 *
 * @author Felix Welberg <felix@welberg.de>
 */
class Sis_Handball_Loader
{

    /**
     * @since 1.0.0
     * @var array $actions
     */
    protected $actions;

    /**
     * @since 1.0.0
     * @var array $filters
     */
    protected $filters;

    /**
     * @since 1.0.23
     * @var array $shortcodes
     */
    protected $shortcodes;

    /**
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->actions = [];
        $this->filters = [];
        $this->shortcodes = [];
    }

    /**
     * Add a new action to the collection to be registered with WordPress.
     *
     * @since 1.0.0
     * @param string $hook
     * @param object $component
     * @param string $callback
     * @param int $priority
     * @param int $accepted_args
     */
    public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1)
    {
        $this->actions = $this->add($this->actions, $hook, $component, $callback, $priority, $accepted_args);
    }

    /**
     * Add a new filter to the collection to be registered with WordPress.
     *
     * @since 1.0.0
     * @param string $hook
     * @param object $component
     * @param string $callback
     * @param int $priority
     * @param int $accepted_args
     */
    public function add_filter($hook, $component, $callback, $priority = 10, $accepted_args = 1)
    {
        $this->filters = $this->add($this->filters, $hook, $component, $callback, $priority, $accepted_args);
    }

    /**
     * Add a new shortcode to the collection to be registered with WordPress
     *
     * @since 1.0.0
     * @param string $tag
     * @param object $component
     * @param string $callback
     */
    public function add_shortcode($tag, $component, $callback, $priority = 10, $accepted_args = 2)
    {
        $this->shortcodes = $this->add($this->shortcodes, $tag, $component, $callback, $priority, $accepted_args);
    }

    /**
     * A utility function that is used to register the actions and hooks into a single collection.
     *
     * @since 1.0.0
     * @param array $hooks
     * @param string $hook
     * @param object $component
     * @param string $callback
     * @param int $priority
     * @param int $accepted_args
     * @return array
     */
    private static function add($hooks, $hook, $component, $callback, $priority, $accepted_args)
    {
        $hooks[] = [
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        ];
        return $hooks;
    }

    /**
     * Register the filters and actions with WordPress.
     *
     * @since 1.0.0
     */
    public function run()
    {
        foreach ($this->filters as $hook) {
            add_filter($hook['hook'], [$hook['component'], $hook['callback']], $hook['priority'], $hook['accepted_args']);
        }
        foreach ($this->actions as $hook) {
            add_action($hook['hook'], [$hook['component'], $hook['callback']], $hook['priority'], $hook['accepted_args']);
        }
        foreach ($this->shortcodes as $hook) {
            add_shortcode($hook['hook'], [$hook['component'], $hook['callback']]);
        }
    }
}
