<?php

namespace Phame\Physics;

/**
 * How a Node3D is held to its parent, beyond being a plain child of its transform. See Node3D::$jointType and
 * docs/PHYSICS-PLAN.md's Phase 2.
 */
enum JointType
{
    /** The parent is only a frame: no constraint at all (the default for every Node3D). */
    case Free;
    /** No motion relative to the parent, ever: welded. A marker for Body's mass/inertia aggregation - nothing
     * here enforces it, a game simply never calls setVelocity()/spin()/setJointRate() on one. */
    case Fixed;
    /** Turns about Node3D::$jointAxisX/Y/Z (a fixed axis, in the parent's space) between $jointMin/$jointMax
     * radians, at a constant rate between events, bouncing (or stopping) at a limit. */
    case Hinge;
    /** As Hinge, but its axis is whatever setJointRate()'s own $axis argument last gave it: a cone limit about
     * Node3D::$jointAxisX/Y/Z (the cone's own fixed axis) approximated as a sequence of single-axis swings, each
     * exact while it lasts (see Node3D::setJointRate()). */
    case Ball;
    /** Slides along Node3D::$jointAxisX/Y/Z (a fixed axis, in the parent's space) between $jointMin/$jointMax
     * units, at a constant rate between events, bouncing (or stopping) at a limit. */
    case Slider;
    /** Free within $jointMax units of the parent's origin, held at it: Physics\Space's own tether, moved onto the
     * parent link (see Node3D::jointRopeUntilTaut()). */
    case Rope;
}
