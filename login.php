<?php
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: index.php");
    exit;
}

// Configuração do banco de dados
$dbname = 'mbilling';
$dbuser = 'mbillingUser';
$dbpass = 'DIGITE SUA SENHA';
$dbhost = 'localhost';

try {
    $db = new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8", $dbuser, $dbpass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro ao conectar ao banco de dados: " . $e->getMessage());
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Por favor, preencha todos os campos.";
    } else {
        try {
            // Query ajustada para incluir id_group
            $stmt = $db->prepare("SELECT username, password, id_group FROM pkg_user WHERE username = ? AND active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            error_log("Tentativa de login - Username: $username");
            if ($user) {
                error_log("Usuário encontrado - Senha no banco: " . $user['password']);
                error_log("Grupo do usuário: " . $user['id_group']);
                error_log("Senha fornecida");

                // Verifica se o usuário pertence aos grupos Administrator (1) ou Gerenciamento (5)
                if ($user['id_group'] != 1 && $user['id_group'] != 5) {
                    $error = "Acesso negado. Apenas usuários dos grupos Administrator e Gerenciamento podem fazer login.";
                    error_log("Acesso negado - Grupo inválido: " . $user['id_group']);
                } else {
                    // Teste 1: Texto puro
                    if ($user['password'] === $password) {
                        error_log("Autenticação bem-sucedida (texto puro)");
                        $_SESSION['loggedin'] = true;
                        $_SESSION['username'] = $user['username'];
                        header("Location: index.php");
                        exit;
                    }
                    // Teste 2: password_verify (BCrypt)
                    elseif (password_verify($password, $user['password'])) {
                        error_log("Autenticação bem-sucedida (password_verify)");
                        $_SESSION['loggedin'] = true;
                        $_SESSION['username'] = $user['username'];
                        header("Location: index.php");
                        exit;
                    }
                    // Teste 3: MD5
                    elseif (md5($password) === $user['password']) {
                        error_log("Autenticação bem-sucedida (MD5)");
                        $_SESSION['loggedin'] = true;
                        $_SESSION['username'] = $user['username'];
                        header("Location: index.php");
                        exit;
                    }
                    // Teste 4: SHA1
                    elseif (sha1($password) === $user['password']) {
                        error_log("Autenticação bem-sucedida (SHA1)");
                        $_SESSION['loggedin'] = true;
                        $_SESSION['username'] = $user['username'];
                        header("Location: index.php");
                        exit;
                    }
                    else {
                        $error = "Usuário ou senha inválidos.";
                        error_log("Falha na autenticação - Nenhum método correspondeu.");
                        error_log("MD5 gerado: " . md5($password));
                        error_log("SHA1 gerado: " . sha1($password));
                    }
                }
            } else {
                $error = "Usuário ou senha inválidos.";
                error_log("Usuário não encontrado ou inativo.");
            }
        } catch (PDOException $e) {
            $error = "Erro ao verificar credenciais: " . $e->getMessage();
            error_log("Erro PDO: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Gerador de Faturas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f0f2f5; /* Cinza claro profissional */
            font-family: 'Helvetica', 'Arial', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 40px;
            border: 1px solid #d0d0d0; /* Borda sutil */
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333333; /* Linha preta sólida */
            padding-bottom: 15px;
        }
        .login-header img {
            height: 40px;
            margin-bottom: 10px;
        }
        .login-header h2 {
            color: #333333; /* Preto suave */
            font-size: 22px;
            font-weight: 700;
            margin: 0;
        }
        .form-label {
            color: #444444;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 6px;
        }
        .form-control {
            border: 1px solid #b0b0b0;
            border-radius: 4px;
            font-size: 14px;
            padding: 10px 12px;
            color: #555555;
        }
        .form-control:focus {
            border-color: #404040;
            box-shadow: 0 0 0 0.2rem rgba(64, 64, 64, 0.25);
        }
        .btn-login {
            width: 100%;
            background-color: #2b2b2b; /* Preto suave */
            border: none;
            color: #ffffff; /* Texto branco para contraste */
            font-weight: 600;
            font-size: 14px;
            padding: 12px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: background-color 0.2s ease;
        }
        .btn-login:hover {
            background-color: #404040; /* Cinza escuro */
            color: #ffffff; /* Mantém o branco no hover */
        }
        .alert-danger {
            background-color: #ffebee;
            border-left: 4px solid #c62828;
            color: #c62828;
            font-size: 14px;
            padding: 12px 20px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="logo.png" alt="Logo" onerror="this.style.display='none'">
            <h2>Login - Gerador de Faturas</h2>
        </div>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-4">
                <label for="username" class="form-label">Usuário</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Senha</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-login">Entrar</button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>