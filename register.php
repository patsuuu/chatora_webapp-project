<?php
session_start();
if (!empty($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register | Chatora</title>
  <link rel="icon" href="logo.png" type="image/png" />
  <link rel="stylesheet" href="style.css" />
</head>
<body class="login-page">
  <div class="login-screen">
    <div class="login-card">
      <div class="login-header">
        <h1>
          <span class="brand">
            <a class="brand-link" href="index.php">
              <img class="brand-logo" src="logo.png" alt="Chatora logo">
            </a>
            <span class="brand-name">Chatora</span>
          </span>
        </h1>
        <p>Mag-register para simulan ang chat.</p>
      </div>

      <div class="account-card login-panel">
        <form id="auth-form">
          <label for="account-username">Username</label>
          <input id="account-username" type="text" placeholder="Piliin ang username" maxlength="20" autocomplete="username" />
          <label for="account-password">Password</label>
          <input id="account-password" type="password" placeholder="Password" maxlength="50" autocomplete="new-password" />
          <div class="account-actions">
            <button id="register-button" type="submit">Register</button>
          </div>
        </form>
        <p class="account-note">May account na? <a href="login.php">Login dito</a>.</p>
      </div>
    </div>
  </div>

  <script src="register.js"></script>
</body>
</html>
