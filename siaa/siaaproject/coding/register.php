<?php
require_once 'db_connect.php';

function checkPasswordStrengthPHP($password) {
    $score = 0;
    if (strlen($password) >= 8) $score++;
    if (preg_match("/[a-z]/", $password)) $score++;
    if (preg_match("/[A-Z]/", $password)) $score++;
    if (preg_match("/[0-9]/", $password)) $score++;
    if (preg_match("/[^a-zA-Z0-9]/", $password)) $score++;
    // A score of 4 or 5 is 'strong' or 'very-strong'.
    // A score of 3 or less is 'medium' or 'weak'.
    return $score;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($password)) {
        $message = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
    } elseif (checkPasswordStrengthPHP($password) < 4) {
        // Block registration if password strength is not 'strong' or 'very-strong'
        $message = "Password is not strong enough. Please use a mix of uppercase, lowercase, numbers, and symbols.";
    } else {
        // Check if username or email already exists using prepared statements
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message = "Username or Email already exists.";
        } else {
            // Hash the password for security
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert new user with a prepared statement
            $insert_stmt = $conn->prepare("INSERT INTO users (username, email, password, first_name, last_name) VALUES (?, ?, ?, ?, ?)");
            $insert_stmt->bind_param("sssss", $username, $email, $hashed_password, $first_name, $last_name);

            if ($insert_stmt->execute()) {
                // Redirect to login page with a success message
                header("Location: login.php?message=" . urlencode("Registered! You can now log in."));
                exit();
            } else {
                $message = "Error: Could not register user.";
            }
            $insert_stmt->close();
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    <title>DAN-LEN</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-image: url('/siaa/siaaproject/coding/backgrounds/loginbg.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .form-wrapper {
            background: #fff;
            border-radius: 24px;
            padding: 2rem;
            text-align: center;
            width: 90%;
            max-width: 400px; 
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        .form-title {
            text-align: center;
            font-size: 2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 1.5rem;
        }
        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .form-input {
            width: 100%;
            padding: 8px 0;
            font-size: 1rem;
            color: #4a5568;
            border: none;
            border-bottom: 2px solid #e2e8f0;
            background: transparent;
            outline: none;
            transition: border-bottom-color 0.3s ease-in-out;
        }
        .form-input:focus {
            border-color: #3b82f6;
        }
        .form-label {
            position: absolute;
            left: 0;
            top: 8px;
            font-size: 1rem;
            color: #a0aec0;
            pointer-events: none;
            transition: 0.3s ease all;
        }
        .form-input:focus ~ .form-label,
        .form-input:not(:placeholder-shown) ~ .form-label {
            top: -16px;
            font-size: 0.875rem;
            color: #6c5ce7;
        }
        .password-toggle {
            position: absolute;
            right: 0;
            top: 8px;
            cursor: pointer;
            color: #a0aec0;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #2d3748;
        }
        .password-strength-meter {
            display: flex;
            gap: 4px;
            height: 4px;
            margin-top: 0.5rem;
        }
        .strength-bar {
            flex-grow: 1;
            background-color: #e5e7eb; /* gray-200 */
            border-radius: 2px;
            transition: background-color 0.3s ease;
        }
        /* Strength level colors */
        .strength-weak .strength-bar:nth-child(-n+1) { background-color: #ef4444; /* red-500 */ }
        .strength-medium .strength-bar:nth-child(-n+2) { background-color: #f97316; /* orange-500 */ }
        .strength-strong .strength-bar:nth-child(-n+3) { background-color: #22c55e; /* green-500 */ }
        .strength-very-strong .strength-bar:nth-child(-n+4) { background-color: #16a34a; /* green-600 */ }
        .strength-text {
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        .hidden { display: none; }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background-color: #4f46e5; /* Tailwind's indigo-600 */
            color: #fff;
            border: none;
            border-radius: 9999px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .btn-submit:hover {
            background-color: #4338ca; /* A slightly darker indigo-700 */
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        .link-text {
            color: #6c5ce7;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .link-text:hover {
            text-decoration: underline;
            color: #5544d1;
        }
    </style>
</head>
<body>

    <!-- Main Content Container -->
    <main class="w-full p-4">
        <div class="form-wrapper mx-auto">
            <?php if (!empty($message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4 text-sm" role="alert">
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>
            <form action="register.php" method="POST">
                <!-- Modern Logo Header -->
                <div class="text-center mb-6">
                    <a href="index.php" class="text-3xl font-bold text-indigo-600 hover:text-indigo-700 transition-colors">DAN-LEN</a>
                </div>
                <h1 class="form-title">Create an Account</h1>
                <div class="form-group">
                    <input type="text" id="first_name" name="first_name" required class="form-input" placeholder=" " autocomplete="given-name" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="first_name" class="form-label">First Name</label>
                </div>
                <div class="form-group">
                    <input type="text" id="last_name" name="last_name" required class="form-input" placeholder=" " autocomplete="family-name" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="last_name" class="form-label">Last Name</label>
                </div>
                <div class="form-group">
                    <input type="text" id="username" name="username" required class="form-input" placeholder=" " autocomplete="username" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="username" class="form-label">Username</label>
                </div>
                <div class="form-group">
                    <input type="email" id="email" name="email" required class="form-input" placeholder=" " autocomplete="email" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="email" class="form-label">Email</label>
                </div>
                <div class="form-group">
                    <input type="password" id="password" name="password" required maxlength="32" class="form-input" placeholder=" " autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="password" class="form-label">Password</label>
                    <span class="password-toggle hidden">
                        <i class="fa-solid fa-eye-slash"></i>
                    </span>
                </div>
                <div class="form-group">
                    <input type="password" id="confirm_password" name="confirm_password" required maxlength="32" class="form-input" placeholder=" " autocomplete="new-password" readonly onfocus="this.removeAttribute('readonly');">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <span class="password-toggle hidden">
                        <i class="fa-solid fa-eye-slash"></i>
                    </span>
                    <div id="password-strength-meter" class="password-strength-meter">
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                        <div class="strength-bar"></div>
                    </div>
                    <p id="password-strength-text" class="strength-text text-gray-500"></p>
                </div>
                <button type="submit" class="btn-submit">Register</button>
            </form>
            <div class="text-center mt-3 text-sm text-gray-500">
                Already have an account? <a href="login.php" class="link-text">Log in here</a>
            </div>
        </div>
    </main>

    <script>
        // Password Toggle
        document.querySelectorAll(".password-toggle").forEach(btn => {
            btn.addEventListener("click", () => {
                const input = btn.parentNode.querySelector("input[type='password'], input[type='text']");
                const icon = btn.querySelector("i");
                if (input.type === "password") {
                    input.type = "text";
                    icon.classList.replace("fa-eye-slash", "fa-eye");
                } else {
                    input.type = "password";
                    icon.classList.replace("fa-eye", "fa-eye-slash");
                }
            });
        });

        // Show/hide password toggle on input and change events
        document.querySelectorAll("input[type='password']").forEach(input => {
            function toggleIcon() {
                const toggle = input.parentNode.querySelector('.password-toggle');
                if (input.value.length > 0) {
                    toggle.classList.remove("hidden");
                } else {
                    toggle.classList.add("hidden");
                }
            }
            
            // Listen for input events (typing)
            input.addEventListener("input", toggleIcon);
            // Listen for change events (autofill)
            input.addEventListener("change", toggleIcon);
            
            // Also check on page load in case of autofill
            // A small delay can help with some browser autofill behaviors
            setTimeout(toggleIcon, 100);
        });

        // Password Strength Meter Logic
        const passwordInput = document.getElementById('password');
        const strengthMeter = document.getElementById('password-strength-meter');
        const strengthText = document.getElementById('password-strength-text');

        passwordInput.addEventListener('input', () => {
            const password = passwordInput.value;
            const strength = checkPasswordStrength(password);
            
            strengthMeter.className = 'password-strength-meter'; // Reset classes
            if (password.length > 0) {
                strengthMeter.classList.add(`strength-${strength.level}`);
            }
            strengthText.textContent = strength.text;
        });

        function checkPasswordStrength(password) {
            let score = 0;
            if (password.length >= 8) score++;
            if (password.match(/[a-z]/)) score++;
            if (password.match(/[A-Z]/)) score++;
            if (password.match(/[0-9]/)) score++;
            if (password.match(/[^a-zA-Z0-9]/)) score++;

            if (password.length === 0) {
                return { level: '', text: '' };
            }
            if (score <= 2) {
                return { level: 'weak', text: 'Weak' };
            } else if (score === 3) {
                return { level: 'medium', text: 'Medium' };
            } else if (score === 4) {
                return { level: 'strong', text: 'Strong' };
            } else {
                return { level: 'very-strong', text: 'Very Strong' };
            }
        }

    </script>

</body>
</html>
