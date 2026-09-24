<?php
require_once __DIR__ . '/session_bootstrap.php';
app_session_start(['remember' => true]);
@include_once "config.php";
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/permissions.php';
$isLoggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';

// 已登入會員才受「遊戲下載」權限控管
if ($isLoggedIn) {
    $Link = @mysqli_connect($DBHost, $DBUser, $DBPassword, $DBName);
    if ($Link) {
        mysqli_set_charset($Link, "utf8");
        $u = currency_resolve_user($Link, $_SESSION['username'], $_SESSION['user_id'] ?? '');
        $uid = (int)$u['uid'];
        if ($uid > 0) {
            member_permission_require($Link, $uid, 'can_download', ['back' => 'home.php', 'countdown' => 5]);
        }
        mysqli_close($Link);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>劍俠世界 | 下載</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=MedievalSharp&display=swap" rel="stylesheet">
    <style>
        :root {
            --steel: #8b8f94;
            --crimson: #a3172c;
            --crimson-bright: #d6293f;
            --gold: #c9a24b;
            --panel: #16181d;
        }
        body {
            background: radial-gradient(circle at 50% 0%, #1c1f26 0%, #0c0d10 70%);
            color: #e8e6df;
            font-family: 'MedievalSharp', 'Cinzel', serif;
            min-height: 100vh;
        }
        .navbar-custom {
            background: linear-gradient(180deg, #16181d 0%, #0c0d10 100%);
            border-bottom: 1px solid #2a2d34;
        }
        .navbar-custom .navbar-brand {
            font-family: 'Cinzel', serif;
            color: var(--gold);
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .navbar-custom a.nav-link {
            color: var(--steel);
            font-family: 'Cinzel', serif;
            letter-spacing: 1px;
        }
        .navbar-custom a.nav-link:hover { color: var(--gold); }
        .container { max-width: 720px; }
        h1.title {
            font-family: 'Cinzel', serif;
            font-weight: 700;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--gold);
            text-shadow: 0 0 12px rgba(201, 162, 75, 0.5), 0 0 2px #000;
        }
        .blade-divider {
            width: 60%;
            margin: 1.5rem auto 2rem auto;
            border: none;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson-bright), var(--gold), var(--crimson-bright), transparent);
        }
        .download-card {
            background: linear-gradient(180deg, var(--panel) 0%, #101116 100%);
            border: 1px solid #2a2d34;
            border-radius: 6px;
            box-shadow: 0 0 25px rgba(0,0,0,0.6);
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .download-card h2 {
            font-family: 'Cinzel', serif;
            color: var(--gold);
            font-size: 1.05rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }
        .download-card p {
            color: var(--steel);
            font-size: 0.85rem;
            margin: 0;
        }
        .btn-download {
            background: linear-gradient(180deg, var(--crimson-bright) 0%, var(--crimson) 100%);
            border: 1px solid #7a1120;
            color: #fff;
            font-family: 'Cinzel', serif;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 0.5rem 1.25rem;
            border-radius: 4px;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-download:hover {
            background: linear-gradient(180deg, #e8384d 0%, var(--crimson-bright) 100%);
            border-color: var(--gold);
            color: #fff;
        }
        .requirements {
            background: linear-gradient(180deg, var(--panel) 0%, #101116 100%);
            border: 1px solid #2a2d34;
            border-radius: 6px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: var(--steel);
        }
        .requirements h2 {
            font-family: 'Cinzel', serif;
            color: var(--gold);
            font-size: 1.05rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 0.75rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand navbar-custom px-4">
        <a class="navbar-brand" href="<?php echo $isLoggedIn ? 'home.php' : 'index.php'; ?>">劍俠世界</a>
        <div class="ms-auto">
            <?php if ($isLoggedIn): ?>
                <a class="nav-link d-inline-block me-3" href="home.php">首頁</a>
                <a class="nav-link d-inline-block me-3" href="usercontrol.php">帳號</a>
                <a class="nav-link d-inline-block" href="logout.php">登出</a>
            <?php else: ?>
                <a class="nav-link d-inline-block me-3" href="login.php">登入</a>
                <a class="nav-link d-inline-block" href="index.php">註冊</a>
            <?php endif; ?>
        </div>
    </nav>
    <div class="container mt-5">
        <h1 class="title text-center">下載</h1>
        <hr class="blade-divider">

        <!-- 注意：請將下方的 href 值替換為你實際的客戶端／更新檔網址 -->
        <div class="download-card">
            <div>
                <h2>完整客戶端</h2>
                <p>完整遊戲安裝套件</p>
            </div>
            <a href="#" class="btn-download">下載</a>
        </div>

        <div class="download-card">
            <div>
                <h2>更新檔 / 更新程式</h2>
                <p>適用於既有安裝的最新更新檔</p>
            </div>
            <a href="#" class="btn-download">下載</a>
        </div>

        <div class="download-card">
            <div>
                <h2>啟動器</h2>
                <p>自動更新的遊戲啟動器</p>
            </div>
            <a href="#" class="btn-download">下載</a>
        </div>

        <div class="requirements">
            <h2>系統需求</h2>
            <p>作業系統：Windows 7 或更新版本 &middot; CPU：雙核心 2.0GHz 以上 &middot; 記憶體：4GB 以上 &middot; 儲存空間：10GB 可用空間 &middot; 顯示卡：相容 DirectX 9</p>
        </div>
    </div>
</body>
</html>