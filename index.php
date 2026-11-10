<?php
/**
 * ArtForge v5 — générateur d'images stylisées multi-utilisateurs
 * PHP 7.4+ / 8.x — PDO_SQLite + cURL
 *
 * Architecture :
 *   index.php   → PHP (auth, DB, AJAX, HTML)
 *   style.css   → CSS pur
 *   js.js       → JS pur (lit window.ARTFORGE pour la config dynamique)
 */

declare(strict_types=1);

@set_time_limit(900);
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '900');

/* ============================================================
   BASE PATH ROBUSTE
============================================================ */
$scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = '';

if ($scriptFile !== '' && $scriptName !== '') {
    $realScript = realpath($scriptFile);
    $realDocRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($realScript !== false && $realDocRoot !== false && strpos($realScript, $realDocRoot) === 0) {
        $rel = substr($realScript, strlen($realDocRoot));
        $rel = str_replace('\\', '/', $rel);
        $basePath = rtrim(dirname($rel), '/\\');
        if ($basePath === '' || $basePath === '.' || $basePath === '/') $basePath = '';
    }
}
if ($basePath === '') {
    $basePath = rtrim(dirname($scriptName), '/\\');
    if ($basePath === '.' || $basePath === '/' || $basePath === '\\') $basePath = '';
}
$GLOBALS['basePath'] = $basePath;

define('DATA_ROOT', __DIR__ . '/data');
define('DB_PATH',   DATA_ROOT . '/artforge.sqlite');
define('AGNES_BASE', 'https://apihub.agnes-ai.com/v1');
define('AGNES_TEXT_MODEL', 'agnes-3.0-flash');
define('AGNES_TEXT_MODEL_FALLBACK', 'agnes-2.5-flash');
define('AGNES_IMG_MODEL', 'agnes-image-2.5-flash');

/* ============================================================
   SESSION
============================================================ */
$action = $_GET['action'] ?? '';
$needsWrite = in_array($action, ['login', 'logout'], true);
if ($needsWrite) {
    session_start();
} else {
    if (PHP_VERSION_ID >= 70000) session_start(['read_and_close' => true]);
    else { session_start(); session_write_close(); }
}
$currentUserId = $_SESSION['user_id'] ?? null;

/* ============================================================
   BASE DE DONNÉES
============================================================ */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0755, true);
    if (!is_file(DATA_ROOT . '/.htaccess')) {
        @file_put_contents(DATA_ROOT . '/.htaccess',
            "Options -Indexes\n<FilesMatch \"\\.(sqlite|sqlite3|db|log|key_offset)$\">\nRequire all denied\n</FilesMatch>\n");
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=10000');

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        pin_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE TABLE IF NOT EXISTS styles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        slug TEXT NOT NULL,
        name TEXT NOT NULL,
        prompt TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, slug)
      );
      CREATE INDEX IF NOT EXISTS idx_styles_user ON styles(user_id);
      CREATE TABLE IF NOT EXISTS generations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        style_slug TEXT,
        user_input TEXT,
        used_image INTEGER DEFAULT 0,
        vision_desc TEXT,
        final_prompt TEXT,
        image_url TEXT,
        parent_id INTEGER DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_gen_user ON generations(user_id);
      CREATE TABLE IF NOT EXISTS storyboards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        slug TEXT NOT NULL,
        name TEXT NOT NULL,
        items TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, slug)
      );
      CREATE INDEX IF NOT EXISTS idx_sb_user ON storyboards(user_id);
      CREATE TABLE IF NOT EXISTS favorites (
        user_id INTEGER NOT NULL,
        generation_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        PRIMARY KEY (user_id, generation_id)
      );
      CREATE INDEX IF NOT EXISTS idx_fav_user ON favorites(user_id);
    ");

    $defaults = defaultStyles();
    $stmt = $pdo->prepare("INSERT INTO styles (user_id, slug, name, prompt) VALUES (0, ?, ?, ?) ON CONFLICT(user_id, slug) DO NOTHING");
    foreach ($defaults as $s) $stmt->execute([$s['slug'], $s['name'], $s['prompt']]);

    $sbDefault = defaultStoryboard();
    $stmtSb = $pdo->prepare("INSERT INTO storyboards (user_id, slug, name, items) VALUES (0, ?, ?, ?) ON CONFLICT(user_id, slug) DO NOTHING");
    $stmtSb->execute([$sbDefault['slug'], $sbDefault['name'], json_encode($sbDefault['items'], JSON_UNESCAPED_UNICODE)]);

    return $pdo;
}

function ensureUserPresets(int $userId): void
{
    $pdo = db();
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM styles WHERE user_id = ?");
    $cnt->execute([$userId]);
    if ((int)$cnt->fetchColumn() === 0) {
        $copy = $pdo->prepare("INSERT OR IGNORE INTO styles (user_id, slug, name, prompt)
                               SELECT ?, slug, name, prompt FROM styles WHERE user_id = 0");
        $copy->execute([$userId]);
    }
    $cntSb = $pdo->prepare("SELECT COUNT(*) FROM storyboards WHERE user_id = ?");
    $cntSb->execute([$userId]);
    if ((int)$cntSb->fetchColumn() === 0) {
        $copySb = $pdo->prepare("INSERT OR IGNORE INTO storyboards (user_id, slug, name, items)
                                 SELECT ?, slug, name, items FROM storyboards WHERE user_id = 0");
        $copySb->execute([$userId]);
    }
}

function defaultStyles(): array
{
    return [
        [
            'slug' => 'wes_anderson',
            'name' => 'Wes Anderson — cinéma argentique',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Wes Anderson's cinematic photography.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: 35mm Kodak Portra 400 film grain, photorealistic cinematic still, Art Deco × Gaudí geometry.\n".
"- Palette: powder blue, hot pink, mustard yellow, beige, turquoise.\n".
"- Lighting: soft diffused natural light.\n".
"- Composition: perfectly symmetrical centered composition, flat 40mm telephoto perspective.\n".
"- Subject: deadpan expression, meticulously designed pastel costumes.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim (identity, pose, clothing). Only restyle.\n".
"- No digital art, no illustration, no 3D render, no anime.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
        [
            'slug' => 'moebius',
            'name' => 'Manga — Mœbius (ligne claire)',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Jean Giraud \"Mœbius\" comic art.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: ligne claire (clear line) drawing, European bande dessinée aesthetic, fine confident ink lines, Moebius sci-fi comic art.\n".
"- Palette: muted pastel watercolor (sandy yellows, dusty blues, pale pinks, mineral greens).\n".
"- Composition: surreal vast landscapes, minimalist backgrounds, clean flat color fills, delicate hatching.\n".
"- Lighting: soft even diffuse light, no harsh shadows.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim. Only restyle.\n".
"- No photograph, no 3D render, no anime.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
        [
            'slug' => 'akira',
            'name' => 'Manga — Akira (Otomo)',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Katsuhiro Otomo's \"Akira\" manga.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: high-contrast black and white ink drawing, Katsuhiro Otomo manga aesthetic, meticulous cross-hatching, screentone textures, sharp angular features.\n".
"- Scene: dense Neo-Tokyo urban detail, dramatic perspective, post-apocalyptic cyberpunk atmosphere.\n".
"- Lighting: hard directional shading, stark black-and-white contrast.\n".
"- Composition: dynamic perspective, detailed backgrounds.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim. Only restyle.\n".
"- No photograph, no color, no 3D render.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
        [
            'slug' => 'schiele',
            'name' => 'Egon Schiele — expressionnisme (non nu)',
            'prompt' =>
"ROLE: You are a world-class prompt engineer specialized in Egon Schiele's expressionist painting.\n".
"TASK: Convert the given scene description into ONE English image prompt (100–140 words).\n".
"PROMPT STRUCTURE (Agnes-recommended order): Subject → Scene → Style → Lighting → Composition → Quality.\n".
"ALWAYS INCLUDE:\n".
"- Style: expressionist oil painting, Egon Schiele aesthetic, elongated distorted bodies, angular gaunt faces, nervous jagged line work, bold outlines, Vienna Secession era.\n".
"- Palette: sickly pale greens and ochres mixed with muted reds and blacks.\n".
"- Lighting: raw emotional intensity, flat stark background.\n".
"- Composition: stark flat background, bold outlines.\n".
"STRICT CONSTRAINT: ALL SUBJECTS MUST BE FULLY CLOTHED — NO NUDITY, NO EROTICISM. If the source mentions nudity or partial undress, dress the subject in appropriate early-1900s Vienna clothing.\n".
"CONSTRAINTS:\n".
"- Preserve the SUBJECT of the source description verbatim (identity, pose). Only restyle.\n".
"- No photograph, no 3D render.\n".
"- Return ONLY the prompt. No quotes, no preamble."
        ],
    ];
}

function defaultStoryboard(): array
{
    return [
        'slug' => 'default_sb',
        'name' => 'Storyboard par défaut',
        'items' => ['gros plan', 'portrait', 'plan américain', 'contre champ', 'ajout d\'une personne', 'objet'],
    ];
}

/* ============================================================
   HELPERS
============================================================ */
function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function sanitizeKeys(array $keys): array
{
    $clean = [];
    foreach ($keys as $k) {
        if (!is_string($k)) continue;
        $k = trim($k);
        if (strlen($k) < 20) continue;
        if (strpos($k, 'sk-') !== 0) continue;
        $clean[] = $k;
    }
    return array_values(array_unique($clean));
}
function ensureUserDir(int $userId): string
{
    $dir = DATA_ROOT . '/u' . $userId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir . '/images')) @mkdir($dir . '/images', 0755, true);
    return $dir;
}
function getKeyOffset(int $n): int
{
    static $fallback = 0;
    if ($n <= 0) return 0;
    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0755, true);
    $file = DATA_ROOT . '/.key_offset';
    $fp = @fopen($file, 'c+');
    if ($fp === false) return ($fallback++) % $n;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return ($fallback++) % $n; }
    $content = (int) trim((string) stream_get_contents($fp));
    $offset  = $content % $n;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string) ($content + 1));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $offset;
}

/* ============================================================
   AGNES HTTP
============================================================ */
class AgnesHttpException extends RuntimeException
{
    public $httpCode;
    public function __construct(string $message, int $httpCode = 0)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

function agnesCall(string $path, array $payload, string $apiKey, int $timeout = 180): array
{
    $ch = curl_init(AGNES_BASE . $path);
    if ($ch === false) throw new RuntimeException('curl_init failed');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Curl: ' . $err);
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? substr($res, 0, 250);
        throw new AgnesHttpException('Agnes HTTP ' . $code . ': ' . $msg, $code);
    }
    return is_array($data) ? $data : [];
}

function agnesCallMulti(string $path, array $payload, array $keys, int $timeout): array
{
    $n = count($keys);
    if ($n === 0) throw new RuntimeException('Aucune clé API disponible');
    $start   = getKeyOffset($n);
    $rotated = array_merge(array_slice($keys, $start), array_slice($keys, 0, $start));
    $lastErr = null;
    $isChat  = (strpos($path, 'chat/completions') !== false);
    $models  = $isChat ? [AGNES_TEXT_MODEL, AGNES_TEXT_MODEL_FALLBACK] : [null];
    foreach ($models as $m) {
        foreach ($rotated as $key) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $p = $payload;
                    if ($m !== null) $p['model'] = $m;
                    return agnesCall($path, $p, $key, $timeout);
                } catch (Throwable $e) {
                    $lastErr  = $e;
                    $httpCode = ($e instanceof AgnesHttpException) ? $e->httpCode : 0;
                    if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) break;
                    if ($attempt === 0) usleep(500000);
                }
            }
        }
    }
    throw $lastErr ?: new RuntimeException('Toutes les clés ont échoué');
}

/* ============================================================
   TÉLÉCHARGEMENT IMAGE
============================================================ */
function downloadImage(string $url, int $userId, string $label): string
{
    $dir = ensureUserDir($userId) . '/images';
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
    if ($safe === '') $safe = 'img';
    if (preg_match('#^data:image/(\w+);base64,#', $url, $m)) {
        $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        $fn  = $safe . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $bin = base64_decode(substr($url, strlen($m[0])), true);
        if ($bin === false || strlen($bin) < 100) throw new RuntimeException('base64 invalide');
        file_put_contents($dir . '/' . $fn, $bin);
    } else {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http','https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL invalide');
        }
        $ext = 'png';
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && preg_match('/\.(jpg|jpeg|png|webp)$/i', $path, $m)) {
            $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        }
        $fn  = $safe . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $fp  = fopen($dir . '/' . $fn, 'wb');
        $ch  = curl_init($url);
        $bytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use ($fp, &$bytes) {
                $bytes += strlen($chunk);
                if ($bytes > 25 * 1024 * 1024) return 0;
                return fwrite($fp, $chunk);
            },
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT         => 120,
            CURLOPT_SSL_VERIFYPEER  => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if (!$ok || $code >= 400 || filesize($dir . '/' . $fn) < 100) {
            @unlink($dir . '/' . $fn);
            throw new RuntimeException('Téléchargement échoué (' . $code . ')');
        }
    }
    return $GLOBALS['basePath'] . '/data/u' . $userId . '/images/' . $fn;
}

/* ============================================================
   SYSTEM PROMPTS
============================================================ */
function visionSystem(): string
{
    return
"You are a professional visual describer. Your output will be used to re-generate the SAME scene in a different art style.\n".
"Describe ONLY what you see, exhaustively and neutrally. Do NOT stylize, do NOT invent.\n".
"Structure your answer with these sections (headings in French, content in French):\n".
"1. PERSONNES — For EACH person: apparent age, face shape, eyes, nose, lips, skin, hair (color/texture/length), body type, posture, expression. Describe clothing item by item with precise colors and materials.\n".
"2. DÉCOR — Location, architecture, furniture, objects, textures, materials.\n".
"3. ÉCLAIRAGE — Light sources, direction, quality, shadows.\n".
"4. ATMOSPHÈRE — Mood, dominant colors, color palette (give hex approximations if possible).\n".
"Rules:\n".
"- If several people are visible, describe each one separately.\n".
"- Do not group people.\n".
"- Be surgical: every detail may be reused verbatim.\n".
"- Length: 180–260 words.";
}

function improveStyleSystem(): string
{
    return
"You are an expert prompt engineer specialised in ART-STYLE SYSTEM PROMPTS for AI image generation.\n".
"Your job is to IMPROVE A TEMPLATE, not to execute it.\n".
"\n".
"CONTEXT:\n".
"The input is a SYSTEM PROMPT / TEMPLATE used by a pipeline. This template teaches an AI how to convert ANY user scene into a final image prompt. It is REUSABLE for any subject (portraits, landscapes, objects, animals, architecture, abstract concepts...).\n".
"\n".
"YOUR TASK:\n".
"Rewrite the template to make it clearer, more precise, and more effective — while keeping it GENERIC and REUSABLE.\n".
"\n".
"CRITICAL RULES — VIOLATION = FAILURE:\n".
"1. DO NOT execute the template. DO NOT generate a scene. DO NOT invent subjects, characters, landscapes, or objects.\n".
"2. The output MUST remain a reusable TEMPLATE with the same structure (ROLE / TASK / PROMPT STRUCTURE / ALWAYS INCLUDE / CONSTRAINTS or equivalent).\n".
"3. Preserve the original artist / style identity completely. Do NOT change the artist. Do NOT add other artists.\n".
"4. Keep the SAME LANGUAGE as the input (French or English).\n".
"5. You MAY add: precise colour palettes (with hex), technique details, lighting rules, composition rules, quality guards, and a clear instruction to preserve the user's subject verbatim.\n".
"6. Structure the prompt in Agnes-recommended order: Subject → Scene → Style → Lighting → Composition → Quality.\n".
"7. Do NOT add scene-specific words. The template must work whether the user inputs 'a portrait of a woman' or 'a spaceship'.\n".
"8. Output ONLY the rewritten template. No preamble. No explanation. No quotation marks. No markdown fences.";
}

function improveStoryboardSystem(): string
{
    return
"You are an expert list-generator AI.\n".
"\n".
"The user will describe the KIND of list they want. Produce EXACTLY that kind of list.\n".
"\n".
"EXAMPLES:\n".
"- 'liste de 100 types de mode avec designers croisés avec photographes' → 'Chanel photographié par Yann Arthus-Bertrand', 'Dior photographié par Annie Leibovitz', etc.\n".
"- 'liste de fruits' → 'pomme', 'banane', 'kiwi', etc.\n".
"- 'liste pour pack storyboard' → 'gros plan', 'contre champ', 'plongée', etc.\n".
"- 'liste de sports' → 'football', 'tennis', 'escrime', etc.\n".
"- 'liste d'idées de scènes cyberpunk' → idées de scènes.\n".
"\n".
"RULES:\n".
"- Read the user's request carefully. Do NOT assume it is a storyboard list.\n".
"- Produce items that match EXACTLY what the user asks for.\n".
"- One item per line. No bullets, no numbering, no quotes, no preamble.\n".
"- Each item must be short (max 120 characters), self-contained, usable as-is.\n".
"- Produce between 8 and 40 items.\n".
"- Reply in the SAME LANGUAGE as the user's request.\n".
"- If the user asks for a cross-product (e.g. brands × photographers), produce the cross-product as separate lines.\n".
"- Only produce storyboard shot types (gros plan, contre champ, etc.) IF the user explicitly asks for a storyboard.\n".
"\n".
"OUTPUT FORMAT (strict):\n".
"One item per line. Nothing else.";
}

/* ============================================================
   ROUTAGE AJAX
============================================================ */
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

   $readOnly = ['me', 'get_styles', 'history', 'get_storyboards', 'get_favorites'];
    if (!in_array($action, $readOnly, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'Méthode HTTP invalide (POST requis)'], 405);
    }

    try {
        switch ($action) {

            case 'login': {
                $b = body();
                $user = trim((string)($b['username'] ?? ''));
                $pin  = trim((string)($b['pin'] ?? ''));
                if (!preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $user)) {
                    respond(['error' => 'Pseudo invalide (2-32 car., a-z 0-9 _ -)'], 400);
                }
                if (!preg_match('/^\d{4}$/', $pin)) {
                    respond(['error' => 'PIN : 4 chiffres requis'], 400);
                }
                $pdo = db();
                $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
                $stmt->execute([$user]);
                $row = $stmt->fetch();
                if ($row) {
                    if (!password_verify($pin, $row['pin_hash'])) respond(['error' => 'PIN incorrect'], 403);
                    $uid = (int)$row['id'];
                } else {
                    $hash = password_hash($pin, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO users (username, pin_hash) VALUES (?, ?)');
                    $stmt->execute([$user, $hash]);
                    $uid = (int)$pdo->lastInsertId();
                }
                ensureUserPresets($uid);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $uid;
                $_SESSION['username'] = $user;
                ensureUserDir($uid);
                respond(['ok' => true, 'user' => $user, 'user_id' => $uid]);
            }

            case 'logout':
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }
                session_destroy();
                respond(['ok' => true]);

            case 'me':
                respond([
                    'user' => $_SESSION['username'] ?? null,
                    'user_id' => $currentUserId,
                ]);

            case 'get_styles': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                ensureUserPresets((int)$currentUserId);
                $rows = db()->prepare("SELECT id, slug, name, prompt, updated_at FROM styles WHERE user_id = ? ORDER BY id");
                $rows->execute([$currentUserId]);
                respond(['ok' => true, 'styles' => $rows->fetchAll()]);
            }

            case 'save_style': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $id     = (int)($b['id'] ?? 0);
                $name   = trim((string)($b['name'] ?? ''));
                $prompt = trim((string)($b['prompt'] ?? ''));
                if ($name === '' || $prompt === '') respond(['error' => 'name et prompt requis'], 400);
                if ($id > 0) {
                    $stmt = db()->prepare("UPDATE styles SET name = ?, prompt = ?, updated_at = datetime('now')
                                           WHERE id = ? AND user_id = ?");
                    $stmt->execute([$name, $prompt, $id, $currentUserId]);
                } else {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $name));
                    $slug = trim($slug, '_') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
                    $stmt = db()->prepare("INSERT INTO styles (user_id, slug, name, prompt) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$currentUserId, $slug, $name, $prompt]);
                }
                respond(['ok' => true]);
            }

            case 'delete_style': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $id = (int)($b['id'] ?? 0);
                $stmt = db()->prepare("DELETE FROM styles WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $currentUserId]);
                respond(['ok' => true]);
            }

            case 'improve_style': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $current = trim((string)($b['prompt'] ?? ''));
                $keys = sanitizeKeys($b['keys'] ?? []);
                if ($current === '' || !$keys) respond(['error' => 'prompt et keys requis'], 400);

                $userMsg =
                    "=== TEMPLATE À AMÉLIORER (À RÉÉCRIRE, NE PAS EXÉCUTER) ===\n" .
                    $current .
                    "\n=== FIN DU TEMPLATE ===\n\n" .
                    "Réécris CE TEMPLATE en conservant son rôle d'instruction générique.\n" .
                    "NE génère AUCUNE scène. NE choisis AUCUN sujet.\n" .
                    "Retourne uniquement le template amélioré, dans la même langue.";

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => improveStyleSystem()],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'temperature' => 0.4,
                    'max_tokens'  => 1200,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                $data = agnesCallMulti('/chat/completions', $payload, $keys, 120);
                $out  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                $out  = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
                if ($out === '') throw new RuntimeException('Réponse IA vide');
                respond(['ok' => true, 'prompt' => trim($out)]);
            }

            case 'get_storyboards': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                ensureUserPresets((int)$currentUserId);
                $rows = db()->prepare("SELECT id, slug, name, items, updated_at FROM storyboards WHERE user_id = ? ORDER BY id");
                $rows->execute([$currentUserId]);
                $out = [];
                foreach ($rows->fetchAll() as $r) {
                    $r['items'] = json_decode($r['items'], true) ?: [];
                    $out[] = $r;
                }
                respond(['ok' => true, 'storyboards' => $out]);
            }

            case 'save_storyboard': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $id     = (int)($b['id'] ?? 0);
                $name   = trim((string)($b['name'] ?? ''));
                $items  = $b['items'] ?? [];
                if ($name === '') respond(['error' => 'name requis'], 400);
                if (!is_array($items)) $items = [];
                $items = array_values(array_filter(array_map(function($x){
                    return trim((string)$x);
                }, $items), function($x){ return $x !== ''; }));
                $jsonItems = json_encode($items, JSON_UNESCAPED_UNICODE);

                if ($id > 0) {
                    $stmt = db()->prepare("UPDATE storyboards SET name = ?, items = ?, updated_at = datetime('now')
                                           WHERE id = ? AND user_id = ?");
                    $stmt->execute([$name, $jsonItems, $id, $currentUserId]);
                } else {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', $name));
                    $slug = trim($slug, '_') . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
                    $stmt = db()->prepare("INSERT INTO storyboards (user_id, slug, name, items) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$currentUserId, $slug, $name, $jsonItems]);
                }
                respond(['ok' => true]);
            }

            case 'delete_storyboard': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $id = (int)($b['id'] ?? 0);
                $stmt = db()->prepare("DELETE FROM storyboards WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $currentUserId]);
                respond(['ok' => true]);
            }

            case 'improve_storyboard': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $theme = trim((string)($b['theme'] ?? ''));
                $keys = sanitizeKeys($b['keys'] ?? []);
                if ($theme === '' || !$keys) respond(['error' => 'theme et keys requis'], 400);

                $userMsg = "LISTE OU THÈME À AMÉLIORER :\n" . $theme .
                    "\n\nRÈGLES STRICTES :\n" .
                    "- Si l'entrée est déjà une liste, NE PAS ajouter d'objets, de vêtements, de lieux ou de détails concrets.\n" .
                    "- Garde chaque élément GÉNÉRIQUE (type de plan / angle caméra uniquement).\n" .
                    "- Corrige l'orthographe, déduplique, et ajoute au maximum 1 ou 2 angles caméra manquants.\n" .
                    "- Un élément par ligne. Pas de puces, pas de numéros, pas de guillemets.";

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => improveStoryboardSystem()],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'temperature' => 0.2,
                    'max_tokens'  => 500,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                $data = agnesCallMulti('/chat/completions', $payload, $keys, 90);
                $out  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                $out  = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
                $lines = preg_split('/\r\n|\r|\n/', $out);
                $items = [];
                foreach ($lines as $ln) {
                    $ln = trim($ln);
                    $ln = preg_replace('/^[\-\*•\d\.\)]+\s*/', '', $ln);
                    $ln = trim($ln, "\"' \t");
                    if ($ln !== '' && mb_strlen($ln) <= 80) $items[] = $ln;
                }
                if (!$items) throw new RuntimeException('Réponse IA vide');
                respond(['ok' => true, 'items' => array_slice($items, 0, 15)]);
            }

            case 'analyze': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $image = (string)($b['image'] ?? '');
                $keys  = sanitizeKeys($b['keys'] ?? []);
                if ($image === '' || !$keys) respond(['error' => 'image et keys requis'], 400);
                if (strlen($image) > 18000000) respond(['error' => 'Image trop volumineuse'], 413);

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => visionSystem()],
                        ['role' => 'user',   'content' => [
                            ['type' => 'text', 'text' => 'Décris cette image.'],
                            ['type' => 'image_url', 'image_url' => ['url' => $image]],
                        ]],
                    ],
                    'temperature' => 0.2,
                    'max_tokens'  => 2000,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                try {
                    $data = agnesCallMulti('/chat/completions', $payload, $keys, 240);
                    $txt  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                    if ($txt === '') throw new RuntimeException('Réponse vide');
                    respond(['ok' => true, 'content' => $txt]);
                } catch (Throwable $e) {
                    respond(['error' => 'Analyse échouée : ' . $e->getMessage()], 500);
                }
            }

            case 'generate_prompt': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $system  = trim((string)($b['system'] ?? ''));
                $userMsg = trim((string)($b['user'] ?? ''));
                $keys    = sanitizeKeys($b['keys'] ?? []);
                $maxTok  = max(80, min(2000, (int)($b['max_tokens'] ?? 500)));
                $temp    = max(0.0, min(1.5, (float)($b['temperature'] ?? 0.75)));
                if ($system === '' || $userMsg === '' || !$keys) {
                    respond(['error' => 'system, user et keys requis'], 400);
                }

                $payload = [
                    'model' => AGNES_TEXT_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => $userMsg],
                    ],
                    'temperature' => $temp,
                    'max_tokens'  => $maxTok,
                    'reasoning_effort' => 'none',
                    'chat_template_kwargs' => ['enable_thinking' => false],
                ];
                try {
                    $data = agnesCallMulti('/chat/completions', $payload, $keys, 120);
                    $out  = trim((string)($data['choices'][0]['message']['content'] ?? ''));
                    $out  = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $out);
                    $out  = trim($out, "\"' \n\r\t");
                    if ($out === '') throw new RuntimeException('Réponse vide');
                    respond(['ok' => true, 'content' => $out]);
                } catch (Throwable $e) {
                    respond(['error' => 'Prompt échoué : ' . $e->getMessage()], 500);
                }
            }

            case 'generate_image': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $prompt = trim((string)($b['prompt'] ?? ''));
                $keys   = sanitizeKeys($b['keys'] ?? []);
                $label  = (string)($b['label'] ?? 'img');
                $size   = (string)($b['size']  ?? '2K');
                $ratio  = (string)($b['ratio'] ?? '9:16');
                if ($prompt === '' || !$keys) respond(['error' => 'prompt et keys requis'], 400);
                if (strlen($prompt) > 20000) respond(['error' => 'Prompt trop long'], 413);
                if (!in_array($size, ['1K','2K','3K','4K'], true)) $size = '2K';
                if (!in_array($ratio, ['1:1','3:4','4:3','16:9','9:16','2:3','3:2','21:9'], true)) $ratio = '9:16';

                $payload = [
                    'model'  => AGNES_IMG_MODEL,
                    'prompt' => mb_substr($prompt, 0, 5000),
                    'size'   => $size,
                    'ratio'  => $ratio,
                    'extra_body' => ['response_format' => 'url'],
                ];
                try {
                    $data = agnesCallMulti('/images/generations', $payload, $keys, 240);
                    $url  = $data['data'][0]['url'] ?? null;
                    if (!$url && isset($data['data'][0]['b64_json'])) {
                        $url = 'data:image/png;base64,' . $data['data'][0]['b64_json'];
                    }
                    if (!$url) throw new RuntimeException('Pas d\'URL dans la réponse');
                    $local = downloadImage($url, $currentUserId, $label);
                    respond(['ok' => true, 'url' => $local, 'remote' => $url]);
                } catch (Throwable $e) {
                    respond(['error' => 'Image échouée : ' . $e->getMessage()], 500);
                }
            }

            case 'log_generation': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $stmt = db()->prepare(
                    "INSERT INTO generations (user_id, style_slug, user_input, used_image, vision_desc, final_prompt, image_url, parent_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $currentUserId,
                    (string)($b['style_slug'] ?? ''),
                    (string)($b['user_input'] ?? ''),
                    (int)($b['used_image'] ?? 0),
                    (string)($b['vision_desc'] ?? ''),
                    (string)($b['final_prompt'] ?? ''),
                    (string)($b['image_url'] ?? ''),
                    isset($b['parent_id']) ? (int)$b['parent_id'] : null,
                ]);
                respond(['ok' => true, 'id' => (int)db()->lastInsertId()]);
            }

            case 'history': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = $_GET;
                $page = max(1, (int)($b['page'] ?? 1));
                $perPage = max(1, min(60, (int)($b['per_page'] ?? 30)));
                $offset = ($page - 1) * $perPage;
                $onlyFav = (int)($b['favorites_only'] ?? 0) === 1;

                $pdo = db();
                $where = "g.user_id = ?";
                $paramsCount = [$currentUserId];
                $paramsRows  = [$currentUserId];

                if ($onlyFav) {
                    $where .= " AND f.generation_id IS NOT NULL";
                }

                $countSql = $onlyFav
                    ? "SELECT COUNT(*) FROM generations g INNER JOIN favorites f ON f.generation_id = g.id AND f.user_id = g.user_id WHERE $where"
                    : "SELECT COUNT(*) FROM generations g WHERE $where";

                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($paramsCount);
                $total = (int)$countStmt->fetchColumn();

                $selectSql = "SELECT g.id, g.style_slug, g.user_input, g.used_image, g.final_prompt, g.image_url, g.parent_id, g.created_at,
                                     CASE WHEN f.generation_id IS NULL THEN 0 ELSE 1 END AS is_favorite
                              FROM generations g
                              LEFT JOIN favorites f ON f.generation_id = g.id AND f.user_id = g.user_id
                              WHERE $where
                              ORDER BY g.id DESC LIMIT ? OFFSET ?";

                $stmt = $pdo->prepare($selectSql);
                $stmt->execute(array_merge($paramsRows, [$perPage, $offset]));
                $items = $stmt->fetchAll();

                respond(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'favorites_only' => $onlyFav ? 1 : 0]);
            }

            case 'toggle_favorite': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = body();
                $genId = (int)($b['generation_id'] ?? 0);
                if ($genId <= 0) respond(['error' => 'generation_id requis'], 400);

                $pdo = db();
                $chk = $pdo->prepare("SELECT 1 FROM generations WHERE id = ? AND user_id = ?");
                $chk->execute([$genId, $currentUserId]);
                if (!$chk->fetchColumn()) respond(['error' => 'Génération introuvable'], 404);

                $exists = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND generation_id = ?");
                $exists->execute([$currentUserId, $genId]);
                $isFav = (bool)$exists->fetchColumn();

                if ($isFav) {
                    $del = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND generation_id = ?");
                    $del->execute([$currentUserId, $genId]);
                    respond(['ok' => true, 'is_favorite' => 0]);
                } else {
                    $ins = $pdo->prepare("INSERT OR IGNORE INTO favorites (user_id, generation_id) VALUES (?, ?)");
                    $ins->execute([$currentUserId, $genId]);
                    respond(['ok' => true, 'is_favorite' => 1]);
                }
            }

            case 'get_favorites': {
                if (!$currentUserId) respond(['error' => 'Non authentifié'], 401);
                $b = $_GET;
                $page = max(1, (int)($b['page'] ?? 1));
                $perPage = max(1, min(60, (int)($b['per_page'] ?? 30)));
                $offset = ($page - 1) * $perPage;

                $pdo = db();
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
                $countStmt->execute([$currentUserId]);
                $total = (int)$countStmt->fetchColumn();

                $stmt = $pdo->prepare(
                    "SELECT g.id, g.style_slug, g.user_input, g.used_image, g.final_prompt, g.image_url, g.parent_id, g.created_at, 1 AS is_favorite
                     FROM favorites f
                     INNER JOIN generations g ON g.id = f.generation_id AND g.user_id = f.user_id
                     WHERE f.user_id = ?
                     ORDER BY f.created_at DESC LIMIT ? OFFSET ?"
                );
                $stmt->execute([$currentUserId, $perPage, $offset]);
                $items = $stmt->fetchAll();

                respond(['ok' => true, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
            }

            case 'test_keys': {
                $b = body();
                $keys = array_slice(sanitizeKeys($b['keys'] ?? []), 0, 30);
                if (!$keys) respond(['error' => 'Aucune clé valide'], 400);

                $mh = curl_multi_init(); $handles = [];
                foreach ($keys as $i => $key) {
                    $ch = curl_init(AGNES_BASE . '/chat/completions');
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'model' => AGNES_TEXT_MODEL,
                            'messages' => [['role' => 'user', 'content' => 'ok']],
                            'max_tokens' => 1,
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);
                    curl_multi_add_handle($mh, $ch);
                    $handles[$i] = $ch;
                }
                do {
                    curl_multi_exec($mh, $running);
                    if ($running) curl_multi_select($mh, 1.0);
                } while ($running > 0);

                $results = [];
                foreach ($handles as $i => $ch) {
                    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $ok = ($code >= 200 && $code < 300) || $code === 429;
                    $results[] = ['key' => $keys[$i], 'ok' => $ok, 'status' => $code];
                    curl_multi_remove_handle($mh, $ch); curl_close($ch);
                }
                curl_multi_close($mh);
                respond(['ok' => true, 'results' => $results]);
            }

            default:
                respond(['error' => 'Action inconnue : ' . $action], 404);
        }
    } catch (Throwable $e) {
        error_log('[ArtForge] ' . get_class($e) . ': ' . $e->getMessage());
        respond(['error' => $e->getMessage()], 500);
    }
    exit;
}

/* ============================================================
   FIN DU ROUTAGE — DÉBUT HTML
============================================================ */
header('Content-Type: text/html; charset=utf-8');
$isLogged = $currentUserId !== null;
$username = $_SESSION['username'] ?? '';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ArtForge — générateur d'images stylisées</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<div id="topbar">
  <span id="modeTag">NON CONFIGURÉ</span>
  <span id="globalStat">Ajoutez vos clés API pour commencer</span>
  <button class="btn-slate hidden" id="logoutBtn" style="margin-left:auto">↻ Déconnexion</button>
</div>

<!-- ================= LOGIN ================= -->
<div id="loginBox" class="card <?= $isLogged ? 'hidden' : '' ?>">
  <h1 style="text-align:center">ArtForge</h1>
  <p class="sub" style="text-align:center">Pseudo + PIN 4 chiffres · Connexion / Inscription en 1 étape</p>
  <label class="lab">Pseudo</label>
  <input type="text" id="loginUser" placeholder="Votre pseudo" autocomplete="username">
  <label class="lab">PIN (4 chiffres)</label>
  <input type="password" id="loginPin" maxlength="4" inputmode="numeric" placeholder="••••" autocomplete="current-password">
  <button class="btn-mega" id="loginBtn">Se connecter / Créer un compte</button>
  <div id="loginStatus" class="status" style="margin-top:.6rem"></div>
</div>

<!-- ================= APP ================= -->
<div id="main" class="<?= $isLogged ? '' : 'hidden' ?>">
  <h1>ArtForge</h1>
  <p class="sub">Photo + prompt + style → image stylisée. Agnes IA · txt2img uniquement.</p>

  <div class="tabs">
    <button class="tab active" data-tab="create">🎨 Créer</button>
    <button class="tab" data-tab="admin">⚙️ Admin styles</button>
    <button class="tab" data-tab="bulk">📋 Bulk</button>
    <button class="tab" data-tab="history">🖼️ Ma bibliothèque</button>
    <button class="tab" data-tab="favorites">⭐ Favoris</button>
  </div>

  <!-- ================= CRÉER ================= -->
  <section id="tab-create">
    <div class="card">
      <label class="lab">Style</label>
      <select id="styleSelect"></select>

      <label class="lab">Prompt / intention (optionnel)</label>
      <textarea id="promptInput" placeholder="Décris la scène, l'ambiance, le personnage… (laisse vide si tu fournis juste une photo)"></textarea>

      <div class="chk-row" id="optimizerRow" style="margin-top:.7rem">
        <input type="checkbox" id="aiOptimizerCheck">
        <label for="aiOptimizerCheck">✨ IA Optimizer</label>
        <span class="hint">désactivé = prompt direct, sans appel IA de rédaction</span>
      </div>
      <p class="sub" style="font-size:.75rem;margin-top:.35rem">Sans optimizer, le prompt utilisateur + le style + chaque élément bulk sont assemblés directement. Avec optimizer, l’IA réécrit chaque prompt, puis l’image démarre immédiatement sans attendre les prompts suivants.</p>

      <label class="lab">Image source (optionnel)</label>
      <div class="drop" id="imageDrop">
        <input type="file" id="imageInput" accept="image/*">
        <div class="icon">📷</div>
        <div class="txt">Cliquez ou glissez une image</div>
      </div>
      <div id="imagePreviewWrap"></div>

      <div class="row" style="margin-top:.9rem">
        <div>
          <label class="lab">Taille</label>
          <select id="sizeSelect">
            <option value="1K">1K</option>
            <option value="2K" selected>2K</option>
            <option value="3K">3K</option>
            <option value="4K">4K</option>
          </select>
        </div>
        <div>
          <label class="lab">Ratio</label>
          <select id="ratioSelect">
            <option value="1:1">1:1</option>
            <option value="3:4">3:4</option>
            <option value="4:3">4:3</option>
            <option value="16:9">16:9</option>
            <option value="9:16" selected>9:16</option>
            <option value="2:3">2:3</option>
            <option value="3:2">3:2</option>
            <option value="21:9">21:9</option>
          </select>
        </div>
      </div>

      <div class="row" style="margin-top:.5rem">
        <div>
          <label class="lab">Nombre d'images (par élément storyboard)</label>
          <input type="number" id="countInput" min="1" max="10" value="1" style="min-height:42px">
        </div>
        <div>
          <label class="lab">Concurrence</label>
          <select id="concurrencySelect">
            <option value="1">1 (séquentiel)</option>
            <option value="2" selected>2</option>
            <option value="3">3</option>
            <option value="4">4</option>
            <option value="5">5</option>
            <option value="6">6</option>
            <option value="8">8</option>
            <option value="10">10</option>
          </select>
        </div>
      </div>

      <div class="chk-row" id="storyboardRow">
        <input type="checkbox" id="storyboardCheck">
        <label for="storyboardCheck">📖 Pack storyboard</label>
        <span class="hint" id="sbHint">— utiliser la liste active pour multiplier les plans</span>
      </div>

      <div id="sbSelectWrap" class="hidden" style="margin-top:.7rem">
        <label class="lab">Storyboard à utiliser</label>
        <select id="storyboardSelect"></select>
      </div>

      <button class="btn-mega" id="generateBtn">🎬 Générer</button>
      <div class="pipeline-ctrl hidden" id="pipelineCtrl">
        <button class="btn btn-cyan" id="pauseBtn">⏸ Pause</button>
        <button class="btn btn-red" id="stopBtn">⏹ Stop</button>
        <button class="btn btn-gold hidden" id="resumeBtn">▶ Reprendre</button>
      </div>
    </div>

    <div id="stepsBox" class="hidden" style="margin-top:1rem"></div>
    <div id="resultsBox" class="hidden">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <h2 style="margin:0;border:none;font-size:1.1rem">Résultats</h2>
        <span class="status" id="resultsCount" style="margin:0;padding:.3rem .7rem">0 / 0</span>
      </div>
      <div class="result-grid" id="resultGrid"></div>
    </div>
  </section>

  <!-- ================= ADMIN ================= -->
  <section id="tab-admin" class="hidden">
    <h2>Mes styles</h2>
    <p class="sub" style="font-size:.82rem">Éditez vos prompts de style. <b>✨ IA améliore</b> réécrit le prompt via Agnes. <b>+ Nouveau style</b> ajoute un style personnalisé.</p>
    <button class="btn btn-gold" id="addStyleBtn" style="margin-bottom:1rem">+ Nouveau style</button>
    <div id="styleEditors"></div>
  </section>

  <!-- ================= BULK ================= -->
  <section id="tab-bulk" class="hidden">
    <h2>Listes storyboard (Bulk)</h2>
    <p class="sub" style="font-size:.82rem">Chaque élément de la liste est une contrainte obligatoire et visible dans l’image. Avec IA Optimizer désactivé, le prompt est assemblé directement ; avec IA Optimizer activé, chaque prompt est optimisé puis son image démarre immédiatement. <b>✨ IA améliore</b> nettoie la liste sans ajouter d'objets inventés.</p>
    <button class="btn btn-gold" id="addSbBtn" style="margin-bottom:1rem">+ Nouvelle liste</button>
    <div id="sbEditors"></div>
  </section>

  <!-- ================= HISTORIQUE ================= -->
  <section id="tab-history" class="hidden">
    <h2>Ma bibliothèque</h2>
    <p class="sub" style="font-size:.82rem">Vos générations par sessions de 3 images visibles. Cliquez sur un bloc de session pour tout voir. Cliquez sur ⭐ pour ajouter aux favoris.</p>
    <div class="filter-bar">
      <label class="chk">
        <input type="checkbox" id="historyFavOnly">
        <span>⭐ Favoris uniquement</span>
      </label>
    </div>
    <div id="historyBox"></div>
    <div id="historyPagination" class="pagination hidden"></div>
  </section>

  <!-- ================= FAVORIS ================= -->
  <section id="tab-favorites" class="hidden">
    <h2>⭐ Mes favoris</h2>
    <p class="sub" style="font-size:.82rem">Toutes les images que vous avez marquées ⭐, les plus récentes en premier.</p>
    <div id="favBox"></div>
    <div id="favPagination" class="pagination hidden"></div>
  </section>
</div>

<!-- ================= PANNEAU CLÉS API ================= -->
<div id="apiPanel">
  <div id="apiHead">
    <span class="lbl">🔑 AGNES API</span>
    <span style="font-size:.7rem;color:var(--ink2);flex:1" id="apiSummary">Cliquez pour configurer</span>
    <span style="font:700 .7rem ui-monospace;color:var(--gold)" id="apiChevron">▲</span>
  </div>
  <div id="apiBody">
    <p style="font-size:.75rem;color:var(--ink2);margin-bottom:.5rem">Clés Agnes (une par ligne, format <code>sk-…</code>). Stockées dans votre navigateur.</p>
    <textarea id="apiInput" placeholder="sk-xxx&#10;sk-yyy" style="min-height:90px;font-family:ui-monospace,monospace;font-size:12px"></textarea>
    <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap">
      <button class="btn btn-gold" id="apiValidate">✓ Valider & tester</button>
      <button class="btn btn-slate" id="apiClear">Effacer</button>
    </div>
    <div id="apiStatus" class="status">Aucune clé configurée</div>
  </div>
</div>

<!-- ================= MODAL ================= -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal-box">
    <h3 id="modalTitle"></h3>
    <p id="modalMsg"></p>
    <div id="modalFieldWrap" class="hidden">
      <label class="lab" id="modalFieldLabel"></label>
      <textarea id="modalFieldInput"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn btn-slate" id="modalCancel">Annuler</button>
      <button class="btn btn-gold" id="modalOk">OK</button>
    </div>
  </div>
</div>

<!-- ================= TOAST ================= -->
<div id="toast"></div>

<!-- ================= CONFIG PHP → JS ================= -->
<script>
window.ARTFORGE = {
  ajaxUrl: <?= json_encode(($basePath ?: '.') . '/' . basename(__FILE__), JSON_UNESCAPED_SLASHES) ?>,
  isLogged: <?= $isLogged ? 'true' : 'false' ?>,
  username: <?= json_encode($username, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="js.js"></script>
</body>
</html>

