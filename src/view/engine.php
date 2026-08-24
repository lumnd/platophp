<?php

/**
 * Template engine contract: what the tpl facade needs from a rendering engine
 *
 * @package  PlatoPHP
 * @license  MIT
 * @link     https://platophp.com
 */

namespace plato\view;

/**
 * The six calls a template engine has to answer.
 *
 * Everything that differs between engines -- whether a template is compiled or included, what a
 * delimiter looks like, whether plugins exist at all, where compiled output is cached -- is the
 * driver's business and reaches it through configure(). The `template` section of config/config.php
 * is passed through whole, minus the `driver` key plato\tpl reads for itself, so a driver names its
 * own settings and no key has to be understood by two classes at once.
 *
 * **Nothing here renders to the browser.** fetch() answers with a string; who echoes it, and what
 * gets appended to it on the way, is decided above this contract. That is what lets a resident
 * worker render a page and hand it back as a reply rather than writing to stdout.
 *
 * **A driver's constructor must not touch the filesystem or build its engine.** Axis C: configure()
 * replaces the settings and drops derived state, and the engine itself is built on the first call
 * that needs it. An application serving JSON never pays for a template engine it does not use, and
 * that laziness is why the engines behind these drivers are Composer suggestions rather than
 * requirements.
 */
interface engine
{
    /**
     * Replace the driver's settings and drop whatever was derived from the previous ones.
     *
     * **The assigned variables go too.** An engine built from the old settings is what a driver
     * derives from them, and a driver that keeps its variables in that engine loses them when it is
     * dropped -- so this is the one behaviour every driver can offer, and leaving it unsaid is what
     * would let two engines disagree about whether a variable assigned before configure() is still
     * there afterwards.
     *
     * @param array<string, mixed> $config The `template` section without its `driver` key
     *
     * @return void
     */
    public function configure(array $config): void;

    /**
     * The settings this driver ended up with, defaults included.
     *
     * On the contract rather than left to each driver so that "which directory is this actually
     * rendering from" is answerable without knowing which engine answered. What the keys are still
     * depends on the driver -- that is the whole point of configure() taking the section whole.
     *
     * @param string|null $key One setting, or null for all of them
     *
     * @return mixed
     */
    public function config(?string $key = null);

    /**
     * Assign a template variable, or a whole array of them when $value is omitted.
     *
     * @param array<string, mixed>|string $tpl_var
     * @param mixed                       $value
     *
     * @return void
     */
    public function assign($tpl_var, $value = null): void;

    /**
     * Whether a template of that name can be rendered.
     *
     * @param string $tpl
     *
     * @return bool
     */
    public function exists(string $tpl): bool;

    /**
     * Render a template and answer with the result. Nothing is echoed.
     *
     * @param string $tpl
     *
     * @return string
     *
     * @throws \RuntimeException When the engine this driver wraps is not installed
     */
    public function fetch(string $tpl): string;

    /**
     * Drop the assigned variables, so the next request of a resident worker starts empty.
     *
     * Called from plato\tpl::reset(), and only for a driver that was actually built: a request
     * that rendered nothing must not construct an engine just to clear it.
     *
     * @return void
     */
    public function clear(): void;
}
