// Phame's browser runtime: connects to a game's Net\View socket (see docs/REMOTE-VIEW.md), mirrors its scene graph,
// evaluates every node's motion description in double precision, and renders it through three.js. A game page does
// not write any rendering code - it loads this module, calls connect(), and adds its own HUD/input on top.
//
//   import { connect } from '/phame.js';
//   const game = connect('wss://.../ws', { canvas });
//   game.on('add', node => ...);           // a node (kind-tagged) entered the scene
//   game.camera.target(nodeId);            // orbit this node; null orbits the world origin
//   game.camera.flyTo(nodeId);             // ease the camera there over a few seconds, then keep following it
//   game.send({ type: 'camera', p, q });   // a game's own input messages (phame.js never sends these on its own)
//   game.timeOffset = 3600;                // seconds added to "now" when evaluating descriptions
//
// Precision at any scale: the three.js camera always sits at the origin; every node's position is set to
// (its world position - the camera's world position), computed in JS doubles, every frame - see computeWorld() and
// Camera.worldPosition(). Only the final per-instance matrix is uploaded to the GPU as float32, by which point the
// magnitudes involved are "distance from the camera", not "distance from a scene's own origin".
//
// Level of detail = instancing: a Mesh node's Geometry and Material are sent once and referenced by id (see
// View::geometryRecord/materialRecord); many games send a fresh Material per body (so the colour can differ), which
// would defeat naive "one draw call per materialId" batching. Instead this runtime groups nodes into one
// THREE.InstancedMesh per (geometry, shader "signature") - colour, emissive and alpha travel as per-instance
// attributes, not material uniforms, recovering the instancing a hand-written client would do by hand. A plain
// Node3D (kind 0) is never drawn - it exists only to be a parent frame, exactly like an anchor or orbit-frame.

// Only three's own module: its addons (e.g. CSS2DRenderer) import 'three' as a bare specifier, which needs an
// import map declared in the *page* before any module runs - not something a module can set up for itself at
// import time. Labels are few (planets, major moons - never an asteroid), so a few lines of projection here is
// simpler than asking every game page to carry that import-map boilerplate just for this.
import * as THREE from 'https://cdn.jsdelivr.net/npm/three@0.186.1/build/three.module.js';

const PROTOCOL_VERSION = 2;
const KIND_PLAIN = 0, KIND_MESH = 1, KIND_LIGHT = 2, KIND_LABEL = 3;

// ---------- GLSL: the browser's equivalent of Phame\Rendering\MeshShader's contract (src/Rendering/MeshShader.php).
// A game's custom `shade()` (and optional `displace()`) GLSL is spliced into this the same way the native renderer
// splices it into its own header/footer - see docs/REMOTE-VIEW.md's Material section. Differences from the native
// contract, both there to make instancing possible (see the module docblock): colour/emissive/alpha are the
// per-instance varyings vColor/vEmissive/vAlpha, not the uniforms uColor/uEmissive the native renderer uses; and
// vLocal (the un-transformed vertex position), vSeed (a per-instance pseudo-random float) and vCenter/vRadius (this
// instance's own world centre and uniform-scale radius) are added, matching the per-instance varyings the earlier
// hand-written WebGL clients already used - RocksGame's Rock/Marble/Observer materials are written against exactly
// these names. SUN (a unit vector) and environment(vec3) are also provided for the same reason (fog, "look at the
// sky" shaders): SUN is the scene's own light direction, not a hard-coded art-directed constant.
// RawShaderMaterial declares nothing for us (unlike ShaderMaterial, which auto-prepends these) - every built-in this
// shader needs, including the per-instance matrix attribute three.js's InstancedMesh supplies, is declared by hand.
const VERTEX_HEADER = `
  in vec3 position; in vec3 normal; in vec2 uv; in mat4 instanceMatrix;
  in vec3 aColor; in vec3 aEmissive; in float aAlpha; in float aSeed;
  uniform mat4 modelMatrix; uniform mat4 viewMatrix; uniform mat4 projectionMatrix; uniform mat3 normalMatrix;
  uniform float uTime;
  out vec3 vNormal; out vec3 vWorldPos; out vec2 vUV; out vec3 vColor; out vec3 vEmissive; out float vAlpha;
  out float vSeed; out vec3 vLocal; out vec3 vCenter; out float vRadius;
`;
const VERTEX_FOOTER = `
  void main() {
    vec3 p = displace(position, normal, uv);
    vLocal = position; vSeed = aSeed; vColor = aColor; vEmissive = aEmissive; vAlpha = aAlpha;
    mat4 im = instanceMatrix;
    vec4 worldPos4 = modelMatrix * im * vec4(p, 1.0);
    vWorldPos = worldPos4.xyz;
    vCenter = (modelMatrix * im * vec4(0.0, 0.0, 0.0, 1.0)).xyz;
    vRadius = length((im * vec4(1.0, 0.0, 0.0, 0.0)).xyz);
    vNormal = normalMatrix * (mat3(im) * normal);
    vUV = uv;
    gl_Position = projectionMatrix * viewMatrix * worldPos4;
  }
`;
const NO_DISPLACE = 'vec3 displace(vec3 pos, vec3 normal, vec2 uv) { return pos; }\n';
const FRAGMENT_HEADER = `
  precision highp float;
  in vec3 vNormal; in vec3 vWorldPos; in vec2 vUV; in vec3 vColor; in vec3 vEmissive; in float vAlpha;
  in float vSeed; in vec3 vLocal; in vec3 vCenter; in float vRadius;
  uniform float uTime; uniform vec3 uEye;
  uniform vec3 uLightDir; uniform vec3 uLightColor; uniform vec3 uAmbient;
  uniform int uPointCount; uniform vec3 uPointPos[4]; uniform vec4 uPointColor[4];
  uniform vec3 uFogColor; uniform vec2 uFogRange;
  uniform float uUnlit; uniform float uSpecular; uniform float uShininess;
  uniform int uOccCount; uniform vec3 uOccPos[4]; uniform float uOccRadius[4];
  out vec4 fragColor;
  vec3 SUN;
  vec3 sceneLight(vec3 n) {
    vec3 sum = uAmbient + uLightColor * max(dot(n, -uLightDir), 0.0);
    for (int i = 0; i < uPointCount; i++) {
      vec3 d = uPointPos[i] - vWorldPos; float dist = max(length(d), 0.0001);
      float att = clamp(1.0 - dist / uPointColor[i].w, 0.0, 1.0);
      sum += uPointColor[i].rgb * (att * att) * max(dot(n, d / dist), 0.0);
    }
    return sum;
  }
  vec3 sceneSpecular(vec3 n) {
    vec3 v = normalize(uEye - vWorldPos);
    vec3 sum = uLightColor * pow(max(dot(n, normalize(v - uLightDir)), 0.0), uShininess);
    for (int i = 0; i < uPointCount; i++) {
      vec3 d = uPointPos[i] - vWorldPos; float dist = max(length(d), 0.0001);
      float att = clamp(1.0 - dist / uPointColor[i].w, 0.0, 1.0);
      sum += uPointColor[i].rgb * (att * att) * pow(max(dot(n, normalize(v + d / dist)), 0.0), uShininess);
    }
    return sum;
  }
  vec3 flatNormal() { vec3 n = normalize(cross(dFdx(vWorldPos), dFdy(vWorldPos))); return gl_FrontFacing ? n : -n; }
  float hash(vec3 p) { p = fract(p * 0.3183099 + 0.1); p *= 17.0; return fract(p.x * p.y * p.z * (p.x + p.y + p.z)); }
  float noise(vec3 x) {
    vec3 i = floor(x), f = fract(x); f = f * f * (3.0 - 2.0 * f);
    return mix(mix(mix(hash(i), hash(i + vec3(1,0,0)), f.x), mix(hash(i + vec3(0,1,0)), hash(i + vec3(1,1,0)), f.x), f.y),
               mix(mix(hash(i + vec3(0,0,1)), hash(i + vec3(1,0,1)), f.x), mix(hash(i + vec3(0,1,1)), hash(i + vec3(1,1,1)), f.x), f.y), f.z);
  }
  float fbm(vec3 p) { float v = 0.0, a = 0.5; for (int i = 0; i < 4; i++) { v += a * noise(p); p = p * 2.03 + 11.7; a *= 0.5; } return v; }
`;
const FRAGMENT_FOOTER = `
  void main() {
    SUN = normalize(-uLightDir);
    vec4 c = shade();
    float fog = clamp((distance(uEye, vWorldPos) - uFogRange.x) / max(uFogRange.y - uFogRange.x, 0.0001), 0.0, 1.0);
    c.rgb = mix(c.rgb, uFogColor, fog);
    fragColor = vec4(c.rgb * c.a, c.a);
  }
`;
// What a Material with no custom shader does: colour (and this instance's emissive/alpha), lit by the scene.
const STANDARD_SHADE = `
  vec4 shade() {
    vec3 n = normalize(gl_FrontFacing ? vNormal : -vNormal);
    vec3 lit = mix(sceneLight(n), vec3(1.0), uUnlit);
    vec3 color = vColor * lit + vEmissive;
    if (uSpecular > 0.0 && uUnlit < 0.5) color += sceneSpecular(n) * uSpecular;
    return vec4(color, vAlpha);
  }
`;
const DEFAULT_ENVIRONMENT = 'vec3 environment(vec3 d) { return mix(vec3(0.01), vec3(0.03, 0.035, 0.05), d.y * 0.5 + 0.5); }';

function colorToRgb(hex) { return [((hex >> 16) & 255) / 255, ((hex >> 8) & 255) / 255, (hex & 255) / 255]; }

/** One shared ShaderMaterial + growable InstancedMesh for every node sharing a (geometry, shader signature) pair. */
class InstanceGroup {
  constructor(scene, geometry, program, sharedUniforms, environmentGLSL) {
    this.capacity = 16;
    this.count = 0;
    this.freeList = [];
    this.nodeBySlot = [];
    this.material = new THREE.RawShaderMaterial({
      glslVersion: THREE.GLSL3, transparent: program.alpha, side: program.doubleSided ? THREE.DoubleSide : THREE.FrontSide,
      uniforms: { ...sharedUniforms, uUnlit: { value: program.unlit ? 1 : 0 }, uSpecular: { value: program.specular }, uShininess: { value: program.shininess } },
      vertexShader: VERTEX_HEADER + (program.displace || NO_DISPLACE) + VERTEX_FOOTER,
      fragmentShader: FRAGMENT_HEADER + environmentGLSL + '\n' + (program.shade || STANDARD_SHADE) + FRAGMENT_FOOTER,
    });
    this.mesh = new THREE.InstancedMesh(geometry, this.material, this.capacity);
    this.mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    this.mesh.frustumCulled = false; // bounds would have to account for every instance's own position; skip it
    this.colorAttr = new THREE.InstancedBufferAttribute(new Float32Array(this.capacity * 3), 3).setUsage(THREE.DynamicDrawUsage);
    this.emissiveAttr = new THREE.InstancedBufferAttribute(new Float32Array(this.capacity * 3), 3).setUsage(THREE.DynamicDrawUsage);
    this.alphaAttr = new THREE.InstancedBufferAttribute(new Float32Array(this.capacity), 1).setUsage(THREE.DynamicDrawUsage);
    this.seedAttr = new THREE.InstancedBufferAttribute(new Float32Array(this.capacity), 1).setUsage(THREE.DynamicDrawUsage);
    this.mesh.geometry.setAttribute('aColor', this.colorAttr);
    this.mesh.geometry.setAttribute('aEmissive', this.emissiveAttr);
    this.mesh.geometry.setAttribute('aAlpha', this.alphaAttr);
    this.mesh.geometry.setAttribute('aSeed', this.seedAttr);
    scene.add(this.mesh);
  }
  grow(scene) {
    const old = this;
    const bigger = Object.create(InstanceGroup.prototype);
    bigger.capacity = old.capacity * 2;
    bigger.count = old.count; bigger.freeList = old.freeList.slice(); bigger.nodeBySlot = old.nodeBySlot.slice();
    bigger.material = old.material;
    bigger.mesh = new THREE.InstancedMesh(old.mesh.geometry, old.material, bigger.capacity);
    bigger.mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
    bigger.mesh.frustumCulled = false;
    bigger.mesh.instanceMatrix.array.set(old.mesh.instanceMatrix.array.subarray(0, old.count * 16));
    for (const [name, size] of [['aColor', 3], ['aEmissive', 3], ['aAlpha', 1], ['aSeed', 1]]) {
      const arr = new Float32Array(bigger.capacity * size);
      arr.set(old.mesh.geometry.getAttribute(name).array.subarray(0, old.count * size));
      const attr = new THREE.InstancedBufferAttribute(arr, size).setUsage(THREE.DynamicDrawUsage);
      bigger.mesh.geometry.setAttribute(name, attr);
      bigger[name.slice(1).toLowerCase() + 'Attr'] = attr;
    }
    scene.remove(old.mesh);
    scene.add(bigger.mesh);
    old.mesh.dispose();
    return bigger;
  }
  /** Reserves a slot for a node in this group. Caller (groupFor()) has already grown it if it was full. */
  alloc(node) {
    const slot = this.freeList.length ? this.freeList.pop() : this.count++;
    this.nodeBySlot[slot] = node;
    const o3 = slot * 3;
    this.colorAttr.array[o3] = node.color[0]; this.colorAttr.array[o3+1] = node.color[1]; this.colorAttr.array[o3+2] = node.color[2];
    this.emissiveAttr.array[o3] = node.emissive[0]; this.emissiveAttr.array[o3+1] = node.emissive[1]; this.emissiveAttr.array[o3+2] = node.emissive[2];
    this.alphaAttr.array[slot] = node.alpha;
    this.seedAttr.array[slot] = (node.id * 0.6180339887) % 1;
    this.colorAttr.needsUpdate = this.emissiveAttr.needsUpdate = this.alphaAttr.needsUpdate = this.seedAttr.needsUpdate = true;
    node.group = this; node.slot = slot;
  }
  free(slot) {
    this.nodeBySlot[slot] = null;
    this.freeList.push(slot);
    // collapse to a point: cheaper than compacting indices, and this slot has no node to draw until reused
    this.mesh.setMatrixAt(slot, new THREE.Matrix4().makeScale(0, 0, 0));
    this.mesh.instanceMatrix.needsUpdate = true;
  }
}

/** A bounding radius for a geometry (for the generic "nearby large occluder" feature - see Phame.connect's docs). */
function boundingRadius(vertices) {
  let r = 0;
  for (let i = 0; i < vertices.length; i += 8) {
    r = Math.max(r, Math.hypot(vertices[i], vertices[i+1], vertices[i+2]));
  }
  return r;
}

export function connect(url, opts = {}) {
  const canvas = opts.canvas ?? document.createElement('canvas');
  if (!opts.canvas) document.body.appendChild(canvas);
  const occluderMinRadius = opts.occluderMinRadius ?? 0; // 0 disables the eclipse-style occluder uniforms entirely

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: opts.antialias ?? false, logarithmicDepthBuffer: true });
  renderer.setClearColor(0x000000, 1);
  let dprCap = opts.maxDpr ?? 2;
  renderer.setPixelRatio(dprCap);
  const scene = new THREE.Scene();
  const near = opts.near ?? 1e-3, far = opts.far ?? 1e7;
  const camera3 = new THREE.PerspectiveCamera(opts.fov ?? 60, 1, near, far);
  const labelsEl = opts.labelsElement ?? (() => { const d = document.createElement('div'); d.style.cssText = 'position:fixed;inset:0;pointer-events:none;overflow:hidden;z-index:2'; document.body.appendChild(d); return d; })();

  const sharedUniforms = {
    uTime: { value: 0 }, uEye: { value: new THREE.Vector3() },
    uLightDir: { value: new THREE.Vector3(-0.4, -1.0, -0.6) }, uLightColor: { value: new THREE.Color(1, 1, 1) }, uAmbient: { value: new THREE.Color(0.25, 0.25, 0.25) },
    uPointCount: { value: 0 }, uPointPos: { value: [0, 1, 2, 3].map(() => new THREE.Vector3()) }, uPointColor: { value: [0, 1, 2, 3].map(() => new THREE.Vector4()) },
    uFogColor: { value: new THREE.Color(0, 0, 0) }, uFogRange: { value: new THREE.Vector2(1e6, 1e7) },
    uOccCount: { value: 0 }, uOccPos: { value: [0, 1, 2, 3].map(() => new THREE.Vector3()) }, uOccRadius: { value: new Float32Array(4) },
  };
  let environmentGLSL = DEFAULT_ENVIRONMENT; // replaced once by the snapshot's scene.environment, if it sets one

  // ---------- node store. Each node is a plain object with fixed numeric fields (a stable hidden class for V8);
  // world position/orientation are written into it in place every frame by computeWorld() - no sub-array, no Map
  // lookup indirection, in the one loop that runs over every node every frame.
  const nodesById = new Map();
  let order = []; // ids, parents before children (append-only; removed entries become null, compacted occasionally)
  let orderDirty = 0;
  const geometries = new Map(); // id -> { threeGeo, boundingRadius } | { pending: url }
  const materials = new Map();  // id -> { shade, displace, unlit, doubleSided, specular, shininess, alpha, hasAlpha, groups: Map<geomId, InstanceGroup> }
  const lights = new Map();     // id -> THREE.PointLight-free bookkeeping (we feed uPointPos/uPointColor ourselves, not three's lighting)
  const labelObjs = new Map();  // id -> node (with a .label element)
  let epoch = null;

  function ensureGeometry(rec) {
    if (geometries.has(rec.id)) return;
    if (rec.url !== undefined) { console.warn('[phame] geometry by url is not yet implemented:', rec.url); geometries.set(rec.id, { threeGeo: new THREE.BoxGeometry(1, 1, 1), boundingRadius: 1 }); return; }
    const n = rec.vertices.length / 8;
    const pos = new Float32Array(n * 3), nrm = new Float32Array(n * 3), uv = new Float32Array(n * 2);
    for (let i = 0; i < n; i++) {
      const o = i * 8; pos[i*3]=rec.vertices[o]; pos[i*3+1]=rec.vertices[o+1]; pos[i*3+2]=rec.vertices[o+2];
      nrm[i*3]=rec.vertices[o+3]; nrm[i*3+1]=rec.vertices[o+4]; nrm[i*3+2]=rec.vertices[o+5];
      uv[i*2]=rec.vertices[o+6]; uv[i*2+1]=rec.vertices[o+7];
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('normal', new THREE.BufferAttribute(nrm, 3));
    geo.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    geo.setIndex(new THREE.BufferAttribute(new Uint16Array(rec.indices), 1));
    geometries.set(rec.id, { threeGeo: geo, boundingRadius: boundingRadius(rec.vertices) });
  }
  // Many games (RocksGame among them) send a fresh Material per body so its colour can differ - View dedups by
  // object identity, so that is a distinct materialId per body, not a shared one. Grouping instances by raw
  // materialId would give every such body its own single-instance InstancedMesh: no batching at all, the one
  // thing standing between this and RocksGame's 700+ bodies costing 700+ draw calls. Instead, instancing groups by
  // a *signature* of everything that is baked into the compiled shader (GLSL source, GL state) - colour, emissive
  // and alpha are already per-instance attributes (see InstanceGroup), so they do not need to be part of it.
  function materialSignature(rec) {
    return [rec.shader || '', rec.displace || '', !!rec.unlit, !!rec.doubleSided, rec.specular ?? 0, rec.shininess ?? 32, (rec.alpha ?? 1) < 1].join('\u0001');
  }
  const groupsBySignature = new Map(); // "signature|geometryId" -> InstanceGroup
  function ensureMaterial(rec) {
    if (materials.has(rec.id)) return;
    if (rec.url !== undefined) { console.warn('[phame] material by url is not yet implemented:', rec.url); materials.set(rec.id, { signature: 'url', shade: null, displace: null, unlit: false, doubleSided: false, specular: 0, shininess: 32, alpha: false, baseColor: [1,1,1], baseEmissive: [0,0,0], baseAlpha: 1 }); return; }
    materials.set(rec.id, {
      signature: materialSignature(rec),
      shade: rec.shader || null, displace: rec.displace || null, unlit: !!rec.unlit, doubleSided: !!rec.doubleSided,
      specular: rec.specular ?? 0, shininess: rec.shininess ?? 32, alpha: (rec.alpha ?? 1) < 1,
      baseColor: colorToRgb(rec.color ?? 0xffffff), baseEmissive: colorToRgb(rec.emissive ?? 0), baseAlpha: rec.alpha ?? 1,
    });
  }
  function groupFor(materialId, geometryId) {
    const mat = materials.get(materialId), geo = geometries.get(geometryId);
    const key = mat.signature + '|' + geometryId;
    let group = groupsBySignature.get(key);
    if (!group) {
      group = new InstanceGroup(scene, geo.threeGeo, mat, sharedUniforms, environmentGLSL);
      groupsBySignature.set(key, group);
    } else if (group.freeList.length === 0 && group.count >= group.capacity) {
      group = group.grow(scene);
      groupsBySignature.set(key, group);
    }
    return group;
  }

  function applyNodeRecord(rec) {
    const [id, parentId, kind, name, visible, scaleX, scaleY, scaleZ, ...rest] = rec;
    let node = nodesById.get(id);
    const isNew = !node;
    if (isNew) { node = { id, group: null, slot: -1, light: null, label: null }; nodesById.set(id, node); order.push(id); }
    node.parentId = parentId; node.kind = kind; node.name = name; node.visible = visible !== false;
    node.scaleX = scaleX; node.scaleY = scaleY; node.scaleZ = scaleZ;
    node.wpx = node.wpx ?? 0; node.wpy = node.wpy ?? 0; node.wpz = node.wpz ?? 0;
    node.wqx = node.wqx ?? 0; node.wqy = node.wqy ?? 0; node.wqz = node.wqz ?? 0; node.wqw = node.wqw ?? 1;
    if (kind === KIND_MESH) {
      const [geometryId, materialId, ...desc] = rest;
      node.desc = desc; node.geometryId = geometryId; node.materialId = materialId;
      const mat = materials.get(materialId);
      node.color = mat.baseColor; node.emissive = mat.baseEmissive; node.alpha = mat.baseAlpha;
      if (node.group) { node.group.free(node.slot); node.group = null; }
      assignToGroup(node, materialId, geometryId);
    } else if (kind === KIND_LIGHT) {
      const [color, intensity, range, lightRadius, ...desc] = rest;
      node.desc = desc; node.lightColor = colorToRgb(color); node.intensity = intensity; node.range = range; node.lightRadius = lightRadius;
      if (!lights.has(id)) lights.set(id, node);
    } else if (kind === KIND_LABEL) {
      const [text, color, ...desc] = rest;
      node.desc = desc; node.text = text; node.labelColor = color;
      if (!node.label) {
        const el = document.createElement('div');
        el.className = 'phame-label';
        el.style.cssText = 'position:absolute;left:0;top:0;color:#fff;font:12px sans-serif;text-shadow:0 0 3px #000;white-space:nowrap;transform:translate(-50%,-50%);pointer-events:auto;cursor:pointer;display:none';
        el.addEventListener('click', () => emit('labelclick', id));
        labelsEl.appendChild(el);
        node.label = el;
        labelObjs.set(id, node);
      }
      node.label.textContent = node.text;
      node.label.style.color = '#' + node.labelColor.toString(16).padStart(6, '0');
    } else {
      node.desc = rest;
    }
    return node;
  }
  function assignToGroup(node, materialId, geometryId) {
    groupFor(materialId, geometryId).alloc(node);
  }
  function removeNode(id) {
    const node = nodesById.get(id);
    if (!node) return;
    if (node.group) node.group.free(node.slot);
    if (node.label) { node.label.remove(); labelObjs.delete(id); }
    lights.delete(id);
    nodesById.delete(id);
    const i = order.indexOf(id);
    if (i >= 0) { order[i] = null; orderDirty++; }
  }
  function compactOrder() {
    if (orderDirty < 64) return;
    order = order.filter(id => id !== null);
    orderDirty = 0;
  }

  // ---------- parent-to-child composition: position = p0 + v*dt + 1/2*a*dt^2 in the parent's space, orientation
  // turned by a constant spin - the same closed form every Net\View description uses, for every game. Plain
  // scalars throughout; nothing here allocates.
  function computeWorld(t) {
    for (let i = 0; i < order.length; i++) {
      const id = order[i];
      if (id === null) continue;
      const n = nodesById.get(id), d = n.desc;
      if (!d) continue; // a light/label/plain node whose description hasn't arrived yet (first frame after an add)
      const dt = t - d[0];
      const lx = d[1] + d[4]*dt + 0.5*d[14]*dt*dt, ly = d[2] + d[5]*dt + 0.5*d[15]*dt*dt, lz = d[3] + d[6]*dt + 0.5*d[16]*dt*dt;
      let qx = d[7], qy = d[8], qz = d[9], qw = d[10];
      const wx = d[11], wy = d[12], wz = d[13], spin = Math.hypot(wx, wy, wz);
      if (spin > 1e-12) {
        const half = spin * dt / 2, s = Math.sin(half) / spin, sx = wx*s, sy = wy*s, sz = wz*s, sw = Math.cos(half);
        const nqx = sw*qx + sx*qw + sy*qz - sz*qy, nqy = sw*qy - sx*qz + sy*qw + sz*qx;
        const nqz = sw*qz + sx*qy - sy*qx + sz*qw, nqw = sw*qw - sx*qx - sy*qy - sz*qz;
        qx = nqx; qy = nqy; qz = nqz; qw = nqw;
      }
      const parent = n.parentId === 0 ? null : nodesById.get(n.parentId);
      const pwx = parent ? parent.wpx : 0, pwy = parent ? parent.wpy : 0, pwz = parent ? parent.wpz : 0;
      const pqx = parent ? parent.wqx : 0, pqy = parent ? parent.wqy : 0, pqz = parent ? parent.wqz : 0, pqw = parent ? parent.wqw : 1;
      const tx = 2*(pqy*lz - pqz*ly), ty = 2*(pqz*lx - pqx*lz), tz = 2*(pqx*ly - pqy*lx);
      const rx = lx + pqw*tx + pqy*tz - pqz*ty, ry = ly + pqw*ty + pqz*tx - pqx*tz, rz = lz + pqw*tz + pqx*ty - pqy*tx;
      n.wpx = pwx + rx; n.wpy = pwy + ry; n.wpz = pwz + rz;
      n.wqx = pqw*qx + pqx*qw + pqy*qz - pqz*qy;
      n.wqy = pqw*qy - pqx*qz + pqy*qw + pqz*qx;
      n.wqz = pqw*qz + pqx*qy - pqy*qx + pqz*qw;
      n.wqw = pqw*qw - pqx*qx - pqy*qy - pqz*qz;
    }
  }

  // ---------- clock sync (ping/pong - see docs/REMOTE-VIEW.md) and connection
  let ws, socketState = 'connecting', offset = null, bestRtt = Infinity, rttAvg = 0, jitter = 0, delay = 0.05, worker = null;
  let bytesSinceStats = 0, descsSinceStats = 0, bps = 0, dps = 0, fps = 0;
  let snapshotStart = 0, snapshotBytes = 0, snapshotLoadMs = null, lastBatchAt = null;
  function serverNow() { return performance.now() / 1000 + (offset ?? 0); }
  function evalTime() { return serverNow() - delay + view.timeOffset; }
  function ping() { if (ws && ws.readyState === 1) ws.send(JSON.stringify({ type: 'ping', c: performance.now() / 1000 })); }
  const listeners = new Map();
  function emit(event, arg) { for (const cb of listeners.get(event) ?? []) cb(arg); }

  function applySnapshot(m) {
    if ((m.geometries)) for (const g of m.geometries) ensureGeometry(g);
    if ((m.materials)) for (const mt of m.materials) ensureMaterial(mt);
    if (m.environment !== undefined) {
      if (m.environment) environmentGLSL = m.environment;
      sharedUniforms.uLightDir.value.set(m.light[0], m.light[1], m.light[2]).normalize();
      sharedUniforms.uLightColor.value.setHex(m.light[3] ?? 0xffffff);
      sharedUniforms.uAmbient.value.setHex(m.ambient ?? 0x404040);
      if (m.fog) { sharedUniforms.uFogColor.value.setHex(m.fog[0]); sharedUniforms.uFogRange.value.set(m.fog[1], m.fog[2]); }
      else sharedUniforms.uFogRange.value.set(1e9, 1e10); // "never" - avoids a GLSL branch in the common no-fog case
    }
    for (const rec of m.nodes) applyNodeRecord(rec);
    if ((m.part ?? 0) === (m.parts ?? 1) - 1) { emit('snapshot'); }
  }
  // An add is not part/parts-numbered like a snapshot (see docs/REMOTE-VIEW.md): a subtree too big for one message
  // arrives as several independent add messages, each already self-contained (any geometry/material a node in it
  // needs - a thrown ragdoll's limbs bringing their own the first time this channel has seen them - travels in the
  // same message, before the nodes that reference it), so each is applied as it arrives, nothing is buffered.
  function applyAdd(m) {
    if (m.geometries) for (const g of m.geometries) ensureGeometry(g);
    if (m.materials) for (const mt of m.materials) ensureMaterial(mt);
    for (const rec of m.nodes) { const n = applyNodeRecord(rec); emit('add', n); }
  }
  function applyRemove(m) { for (const id of m.ids) { emit('remove', id); removeNode(id); } }
  function applyBatch(v) {
    if (v.getUint8(0) !== PROTOCOL_VERSION) { console.error('[phame] protocol version mismatch, refusing to render'); return; }
    lastBatchAt = performance.now();
    for (let o = 9; o + 88 <= v.byteLength; o += 88) {
      const id = v.getUint32(o, true), n = nodesById.get(id);
      if (!n) continue;
      const d = n.desc && n.desc.length === 17 ? n.desc : new Array(17);
      d[0] = v.getFloat64(o + 4, true); d[1] = v.getFloat64(o + 12, true); d[2] = v.getFloat64(o + 20, true); d[3] = v.getFloat64(o + 28, true);
      for (let k = 0; k < 13; k++) d[4 + k] = v.getFloat32(o + 36 + 4 * k, true);
      n.desc = d;
      descsSinceStats++;
    }
  }
  function connectSocket() {
    ws = new WebSocket(url);
    ws.binaryType = 'arraybuffer';
    ws.onopen = () => { socketState = 'joining'; ping(); };
    clearInterval(ws._pinger); ws._pinger = setInterval(ping, 1000);
    ws.onclose = () => { socketState = 'reconnecting'; setTimeout(connectSocket, 1500); };
    ws.onmessage = e => {
      if (typeof e.data !== 'string') { bytesSinceStats += e.data.byteLength; applyBatch(new DataView(e.data)); return; }
      bytesSinceStats += e.data.length;
      const m = JSON.parse(e.data);
      if (m.v !== undefined && m.v !== PROTOCOL_VERSION) { console.error('[phame] protocol version mismatch, refusing to render'); return; }
      if (m.type === 'pong') {
        worker = m.w;
        const now = performance.now() / 1000, rtt = now - m.c;
        rttAvg = rttAvg ? rttAvg * 0.9 + rtt * 0.1 : rtt;
        jitter = jitter * 0.9 + Math.abs(rtt - rttAvg) * 0.1;
        if (rtt <= bestRtt * 1.2 || offset === null) { bestRtt = Math.min(bestRtt, rtt); offset = m.s + rtt / 2 - now; }
        delay = Math.max(0.03, rttAvg / 2 + 3 * jitter);
      } else if (m.type === 'epoch') {
        epoch = m.epoch;
      } else if (m.type === 'snapshot') {
        if ((m.part ?? 0) === 0) { snapshotStart = performance.now(); snapshotBytes = 0; socketState = 'live'; }
        snapshotBytes += e.data.length;
        applySnapshot(m);
        if ((m.part ?? 0) === (m.parts ?? 1) - 1) snapshotLoadMs = performance.now() - snapshotStart;
      } else if (m.type === 'add') {
        applyAdd(m);
      } else if (m.type === 'remove') {
        applyRemove(m);
      }
    };
  }
  connectSocket();

  // ---------- camera: orbits a target node (or the world origin) at (distance, yaw, pitch); flyTo() blends the
  // camera's world position over a fixed wall-clock duration regardless of real distance, then tracking resumes.
  let targetId = null, distance = opts.startDistance ?? 50, yaw = 0, pitch = 0.3, travel = null;
  function offsetVector(d, yaw, pitch) { return [d * Math.cos(pitch) * Math.sin(yaw), d * Math.sin(pitch), d * Math.cos(pitch) * Math.cos(yaw)]; }
  function nodePos(id) { const n = id !== null ? nodesById.get(id) : null; return n ? [n.wpx, n.wpy, n.wpz] : [0, 0, 0]; }
  function easeInOutCubic(t) { return t < 0.5 ? 4*t*t*t : 1 - Math.pow(-2*t + 2, 3) / 2; }
  function cameraWorldPos() {
    if (travel) {
      const elapsed = (performance.now() - travel.start) / travel.duration;
      if (elapsed >= 1) { distance = travel.toDistance; travel = null; }
      else {
        const e = easeInOutCubic(Math.min(1, elapsed));
        const toPos = nodePos(travel.toId);
        distance = travel.fromDistance + (travel.toDistance - travel.fromDistance) * e;
        const off = offsetVector(distance, yaw, pitch);
        const toCam = [toPos[0] + off[0], toPos[1] + off[1], toPos[2] + off[2]];
        return [0, 1, 2].map(i => travel.from[i] + (toCam[i] - travel.from[i]) * e);
      }
    }
    const p = nodePos(targetId), off = offsetVector(distance, yaw, pitch);
    return [p[0] + off[0], p[1] + off[1], p[2] + off[2]];
  }
  let camPos = [0, 0, 0];

  // input: drag to orbit, wheel/pinch to zoom - identical on every phame.js page
  let drag = null, pointers = new Map(), pinch = null;
  canvas.style.touchAction = 'none';
  canvas.addEventListener('pointerdown', e => { pointers.set(e.pointerId, { x: e.clientX, y: e.clientY }); canvas.setPointerCapture(e.pointerId); drag = pointers.size === 1 ? [e.clientX, e.clientY] : null; pinch = null; });
  canvas.addEventListener('pointermove', e => {
    const p = pointers.get(e.pointerId); if (!p) return;
    p.x = e.clientX; p.y = e.clientY;
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()], span = Math.hypot(a.x - b.x, a.y - b.y);
      if (pinch) distance = Math.max(1e-6, Math.min(opts.maxDistance ?? 1e10, distance * pinch / span));
      pinch = span;
    } else if (drag) {
      yaw -= (e.clientX - drag[0]) * 0.006; pitch = Math.max(-1.45, Math.min(1.45, pitch + (e.clientY - drag[1]) * 0.006)); drag = [e.clientX, e.clientY];
    }
  });
  function pointerEnd(e) { pointers.delete(e.pointerId); drag = null; pinch = null; }
  canvas.addEventListener('pointerup', pointerEnd);
  canvas.addEventListener('pointercancel', pointerEnd);
  canvas.addEventListener('wheel', e => { e.preventDefault(); distance = Math.max(1e-6, Math.min(opts.maxDistance ?? 1e10, distance * Math.exp(e.deltaY * 0.0012))); }, { passive: false });

  // ---------- occluder uniforms (opt-in via occluderMinRadius): the nearest few other "large" meshes to the
  // current camera target, reselected a few times a second - generic enough for any game wanting soft/eclipse-style
  // shadows, not solar-specific. See the module docblock for what this does and does not solve.
  let occIds = [], lastOccAt = -Infinity;
  function maybeReselectOccluders(t) {
    if (occluderMinRadius <= 0 || (t - lastOccAt < 0.3 && occIds.length)) return;
    lastOccAt = t;
    const tn = targetId !== null ? nodesById.get(targetId) : null;
    if (!tn) { occIds = []; return; }
    const best = [], bestD = [];
    for (const [id, n] of nodesById) {
      if (id === targetId || n.kind !== KIND_MESH) continue;
      const geo = geometries.get(n.geometryId);
      const r = (geo?.boundingRadius ?? 0) * Math.max(n.scaleX, n.scaleY, n.scaleZ);
      if (r < occluderMinRadius) continue;
      const d = (n.wpx-tn.wpx)**2 + (n.wpy-tn.wpy)**2 + (n.wpz-tn.wpz)**2;
      let k = best.length;
      while (k > 0 && bestD[k-1] > d) k--;
      if (k < 4) { best.splice(k, 0, id); bestD.splice(k, 0, d); if (best.length > 4) { best.pop(); bestD.pop(); } }
    }
    occIds = best;
  }

  // ---------- render loop
  let frames = 0, lastStats = performance.now(), lastCw = -1, lastCh = -1;
  function frame() {
    const t0 = performance.now();
    const cw = canvas.clientWidth || 1, ch = canvas.clientHeight || 1;
    if (cw !== lastCw || ch !== lastCh) { renderer.setSize(cw, ch, false); camera3.aspect = cw / ch; camera3.updateProjectionMatrix(); lastCw = cw; lastCh = ch; }
    if (renderer.getPixelRatio() !== dprCap) renderer.setPixelRatio(dprCap);
    compactOrder();

    if (epoch !== null) {
      const t = evalTime();
      computeWorld(t);
      camPos = cameraWorldPos();
      const [camX, camY, camZ] = camPos;
      const tn = targetId !== null ? nodesById.get(targetId) : null;
      camera3.position.set(0, 0, 0);
      camera3.up.set(0, 1, 0);
      camera3.lookAt(tn ? tn.wpx - camX : 0, tn ? tn.wpy - camY : 0, tn ? tn.wpz - camZ : 0);
      sharedUniforms.uTime.value = t;
      sharedUniforms.uEye.value.set(0, 0, 0); // the camera is always at the origin in this space

      maybeReselectOccluders(t);
      sharedUniforms.uOccCount.value = occIds.length;
      occIds.forEach((id, i) => { const n = nodesById.get(id); sharedUniforms.uOccPos.value[i].set(n.wpx - camX, n.wpy - camY, n.wpz - camZ); sharedUniforms.uOccRadius.value[i] = (geometries.get(n.geometryId)?.boundingRadius ?? 0) * Math.max(n.scaleX, n.scaleY, n.scaleZ); });

      // point lights: nearest 4 to the camera (every shaded material sees the same 4 - matching the native
      // renderer's "first 4 visible point lights" rule as closely as a per-scene, not per-fragment, choice allows)
      const nearLights = [...lights.values()].sort((a, b) => ((a.wpx-camX)**2+(a.wpy-camY)**2+(a.wpz-camZ)**2) - ((b.wpx-camX)**2+(b.wpy-camY)**2+(b.wpz-camZ)**2)).slice(0, 4);
      sharedUniforms.uPointCount.value = nearLights.length;
      nearLights.forEach((n, i) => {
        sharedUniforms.uPointPos.value[i].set(n.wpx - camX, n.wpy - camY, n.wpz - camZ);
        sharedUniforms.uPointColor.value[i].set(n.lightColor[0] * n.intensity, n.lightColor[1] * n.intensity, n.lightColor[2] * n.intensity, n.range);
      });

      // mesh instances: write this frame's camera-relative transform into each instance's matrix slot. A minimum
      // apparent size (minPixels, default a couple of pixels) keeps a distant small body from shrinking to nothing -
      // the same thing the solar system's old hand-rolled client did, now generic: any game gets it for free.
      const screenFactor = (ch / 2) / Math.tan((camera3.fov * Math.PI / 180) / 2);
      const minPixels = opts.minPixels ?? 2;
      for (const group of groupsBySignature.values()) {
        let touched = false;
        for (let slot = 0; slot < group.count; slot++) {
          const n = group.nodeBySlot[slot];
          if (!n) continue;
          touched = true;
          const rx = n.wpx - camX, ry = n.wpy - camY, rz = n.wpz - camZ;
          let sx = n.scaleX, sy = n.scaleY, sz = n.scaleZ;
          if (n.visible) {
            const dist = Math.hypot(rx, ry, rz), apparent = Math.max(sx, sy, sz) / Math.max(dist, 1e-9) * screenFactor;
            if (apparent > 0 && apparent < minPixels) { const k = minPixels / apparent; sx *= k; sy *= k; sz *= k; }
          } else { sx = sy = sz = 0; }
          const m = _m1.makeRotationFromQuaternion(_q1.set(n.wqx, n.wqy, n.wqz, n.wqw));
          m.scale(_v1.set(sx, sy, sz));
          m.setPosition(rx, ry, rz);
          group.mesh.setMatrixAt(slot, m);
        }
        if (touched) group.mesh.instanceMatrix.needsUpdate = true;
      }

      // labels: project each one's camera-relative position through the camera, same as three's own Vector3.project
      for (const n of labelObjs.values()) {
        _v1.set(n.wpx - camX, n.wpy - camY, n.wpz - camZ).project(camera3);
        const visible = _v1.z < 1 && _v1.z > -1;
        n.label.style.display = visible ? '' : 'none';
        if (visible) { n.label.style.left = ((_v1.x * 0.5 + 0.5) * cw) + 'px'; n.label.style.top = ((1 - (_v1.y * 0.5 + 0.5)) * ch) + 'px'; }
      }

      renderer.render(scene, camera3);
      emit('tick', t);
    }

    frames++;
    const now = performance.now();
    if (now - lastStats >= 500) {
      const secs = (now - lastStats) / 1000;
      fps = frames / secs; bps = bytesSinceStats / secs; dps = descsSinceStats / secs;
      frames = 0; bytesSinceStats = 0; descsSinceStats = 0; lastStats = now;
      if (fps < 45 && dprCap > 1) dprCap = Math.max(1, dprCap - 0.25);
      else if (fps > 58 && dprCap < (opts.maxDpr ?? 2)) dprCap = Math.min(opts.maxDpr ?? 2, dprCap + 0.25);
      emit('stats', view.stats);
    }
    requestAnimationFrame(frame);
  }
  const _m1 = new THREE.Matrix4(), _q1 = new THREE.Quaternion(), _v1 = new THREE.Vector3();
  requestAnimationFrame(frame);

  // ---------- the public API
  const view = {
    THREE, scene, renderer, threeCamera: camera3,
    on(event, cb) { (listeners.get(event) ?? listeners.set(event, []).get(event)).push(cb); return view; },
    send(obj) { if (ws && ws.readyState === 1) ws.send(JSON.stringify(obj)); },
    pick(clientX, clientY) {
      const rect = canvas.getBoundingClientRect();
      const ndc = new THREE.Vector2(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
      const ray = new THREE.Raycaster(); ray.setFromCamera(ndc, camera3);
      let best = null, bestDist = Infinity;
      for (const group of groupsBySignature.values()) {
        const hits = ray.intersectObject(group.mesh);
        for (const h of hits) { if (h.distance < bestDist) { bestDist = h.distance; best = group.nodeBySlot[h.instanceId]; } }
      }
      return best ? best.id : null;
    },
    nodeName(id) { return nodesById.get(id)?.name ?? ''; },
    /** Every node currently known, as {id, parentId, kind, name, visible} - e.g. to build a name->id map after 'snapshot'. */
    get nodes() { return [...nodesById.values()].map(n => ({ id: n.id, parentId: n.parentId, kind: n.kind, name: n.name, visible: n.visible })); },
    nodePosition(id) { return nodePos(id); },
    get epoch() { return epoch; },
    get evalTime() { return epoch !== null ? evalTime() : null; },
    get cameraPosition() { return camPos; },
    get socketState() { return socketState; },
    get stats() { return { fps, bps, dps, worker, rtt: rttAvg, snapshotBytes, snapshotLoadMs, lastBatchAt, dprCap }; },
    timeOffset: 0,
    /** The orbit camera: target()/flyTo() a node id, or null to orbit the world origin. */
    camera: {
      target(id) { targetId = id; return view.camera; },
      get targetId() { return targetId; },
      flyTo(id, customDistance) {
        if (id === targetId && !travel) return;
        const from = cameraWorldPos();
        const n = id !== null ? nodesById.get(id) : null;
        const geo = n && n.kind === KIND_MESH ? geometries.get(n.geometryId) : null;
        const r = geo ? geo.boundingRadius * Math.max(n.scaleX, n.scaleY, n.scaleZ) : 0;
        travel = { from, fromDistance: distance, toId: id, toDistance: customDistance ?? Math.max(r * 7, 1), start: performance.now(), duration: opts.flightDuration ?? 3000 };
        targetId = id;
      },
      get distance() { return distance; }, set distance(v) { distance = v; },
      get yaw() { return yaw; }, set yaw(v) { yaw = v; },
      get pitch() { return pitch; }, set pitch(v) { pitch = v; },
      get travelling() { return travel !== null; },
    },
  };
  return view;
}
