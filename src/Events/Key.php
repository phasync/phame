<?php

namespace Phame\Events;

/** Key codes for Engine::isKeyDown(), Async::awaitKey() and KeyEvent::$keyCode. They are SDL keycodes: printable keys are their lowercase ASCII value, so `Key::A === ord('a')`. */
final class Key
{
    public const BACKSPACE = 8;
    public const TAB = 9;
    public const ENTER = 13;
    public const ESCAPE = 27;
    public const SPACE = 32;
    public const NUM_0 = 48;
    public const NUM_1 = 49;
    public const NUM_2 = 50;
    public const NUM_3 = 51;
    public const NUM_4 = 52;
    public const NUM_5 = 53;
    public const NUM_6 = 54;
    public const NUM_7 = 55;
    public const NUM_8 = 56;
    public const NUM_9 = 57;
    public const A = 97;
    public const B = 98;
    public const C = 99;
    public const D = 100;
    public const E = 101;
    public const F = 102;
    public const G = 103;
    public const H = 104;
    public const I = 105;
    public const J = 106;
    public const K = 107;
    public const L = 108;
    public const M = 109;
    public const N = 110;
    public const O = 111;
    public const P = 112;
    public const Q = 113;
    public const R = 114;
    public const S = 115;
    public const T = 116;
    public const U = 117;
    public const V = 118;
    public const W = 119;
    public const X = 120;
    public const Y = 121;
    public const Z = 122;
    public const DELETE = 127;
    public const F1 = 0x4000003A;
    public const F2 = 0x4000003B;
    public const F3 = 0x4000003C;
    public const F4 = 0x4000003D;
    public const F5 = 0x4000003E;
    public const F6 = 0x4000003F;
    public const F7 = 0x40000040;
    public const F8 = 0x40000041;
    public const F9 = 0x40000042;
    public const F10 = 0x40000043;
    public const F11 = 0x40000044;
    public const F12 = 0x40000045;
    public const RIGHT = 0x4000004F;
    public const LEFT = 0x40000050;
    public const DOWN = 0x40000051;
    public const UP = 0x40000052;
    public const LEFT_CTRL = 0x400000E0;
    public const LEFT_SHIFT = 0x400000E1;
    public const LEFT_ALT = 0x400000E2;
    public const RIGHT_CTRL = 0x400000E4;
    public const RIGHT_SHIFT = 0x400000E5;
    public const RIGHT_ALT = 0x400000E6;
}
