<?php

namespace Phame\Wake;

use Phame\Kinetic\Group;

/**
 * Something that ends a DisplayObject::wait() early: the subject comes near or touches a member of a group, or
 * leaves the world. Conditions are shared, immutable values: the factories return the same object for the same
 * arguments, so a thousand bullets waiting for the same thing cost nothing extra to describe.
 *
 * ```php
 * $bulletWake = Wake::any(Wake::collision($enemies), Wake::leaveWorld());
 * $why = $bullet->wait(2.0, $bulletWake);    // null after 2 s, else the condition that fired
 * ```
 */
final class Wake
{
    public const PROXIMITY = 1;
    public const LEAVE_WORLD = 2;
    public const ANY = 3;

    public const WOKEN = 4;

    private static ?self $leaveWorld = null;
    private static ?self $woken = null;
    /** @var ?\WeakMap<Group, array<string, self>> */
    private static ?\WeakMap $byGroup = null;
    /** @var array<string, self> */
    private static array $anyCache = [];

    /** @param list<self> $members */
    private function __construct(
        public readonly int $kind,
        public readonly float $radius = 0.0,
        public readonly ?Group $group = null,
        public readonly array $members = [],
    ) {
    }

    /** The subject's box, grown by $radius on every side, overlaps a visible child of $group. */
    public static function proximity(float $radius, Group $group): self
    {
        self::$byGroup ??= new \WeakMap();
        $key = (string) $radius;
        $cached = self::$byGroup[$group] ?? [];
        if (!isset($cached[$key])) {
            $cached[$key] = new self(self::PROXIMITY, $radius, $group);
            self::$byGroup[$group] = $cached;
        }
        return $cached[$key];
    }

    /** The subject's box overlaps a visible child of $group. */
    public static function collision(Group $group): self
    {
        return self::proximity(0.0, $group);
    }

    /** The subject's box is entirely outside its world: the stage in 2D, the Scene3D's world box in 3D. */
    public static function leaveWorld(): self
    {
        return self::$leaveWorld ??= new self(self::LEAVE_WORLD);
    }

    /** What wait() returns when $world->wake() ended it (after a collision, for example). */
    public static function woken(): self
    {
        return self::$woken ??= new self(self::WOKEN);
    }

    /** Any of the conditions: the wait returns the one that fired. */
    public static function any(self ...$wakes): self
    {
        $key = '';
        foreach ($wakes as $w) {
            $key .= spl_object_id($w) . ',';
        }
        return self::$anyCache[$key] ??= new self(self::ANY, 0.0, null, array_values($wakes));
    }
}
