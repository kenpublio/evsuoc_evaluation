<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Initialize objects
$auth = new Auth();
$functions = new Functions();
$error = '';
$success = '';
$showRegister = false; // Default to login form

// ============================================
// CREATE/CHECK REQUIRED TABLES AND COLUMNS
// ============================================
$conn = getDB();

// Add enrollment_status column to users table if it doesn't exist (US-16)
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'enrollment_status'");
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN enrollment_status ENUM('enrolled', 'graduated', 'inactive') DEFAULT 'enrolled' AFTER is_active");
    $conn->query("ALTER TABLE users ADD COLUMN last_enrolled DATE DEFAULT NULL AFTER enrollment_status");
}

// Create service_types table if it doesn't exist (US-02)
$conn->query("
    CREATE TABLE IF NOT EXISTS service_types (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(50) NOT NULL,
        description TEXT,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Insert default service types if table is empty
$check = $conn->query("SELECT COUNT(*) as count FROM service_types")->fetch_assoc();
if ($check['count'] == 0) {
    $conn->query("INSERT INTO service_types (name, description) VALUES 
        ('TOR', 'Transcript of Records Request'),
        ('Certification', 'Certification of Grades/Enrollment'),
        ('Enrollment', 'Enrollment Processing'),
        ('Diploma', 'Diploma Request'),
        ('Authentication', 'Document Authentication')");
}

// Add service_type_id column to responses if it doesn't exist
$result = $conn->query("SHOW COLUMNS FROM responses LIKE 'service_type_id'");
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE responses ADD COLUMN service_type_id INT AFTER office_id");
}

// Create survey_availability table if it doesn't exist (US-12)
$conn->query("
    CREATE TABLE IF NOT EXISTS survey_availability (
        id INT PRIMARY KEY AUTO_INCREMENT,
        is_active BOOLEAN DEFAULT FALSE,
        start_date DATE,
        end_date DATE,
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// Insert default survey settings if none exist
$check = $conn->query("SELECT COUNT(*) as count FROM survey_availability")->fetch_assoc();
if ($check['count'] == 0) {
    $conn->query("INSERT INTO survey_availability (is_active, start_date, end_date) VALUES (1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle login
    if (isset($_POST['login'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        
        // Basic validation
        if (empty($username) || empty($password)) {
            $error = "Please enter both username and password!";
        } else {
            // Check login credentials
            $user = $auth->login($username, $password);
            
            if ($user) {
                // US-16: Check enrollment status for students
                if ($user['role'] === 'student') {
                    // Get enrollment status
                    $stmt = $conn->prepare("SELECT enrollment_status FROM users WHERE id = ?");
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $user_data = $result->fetch_assoc();
                    
                    $enrollment_status = $user_data['enrollment_status'] ?? 'enrolled';
                    
                    // Only enrolled students can access
                    if ($enrollment_status !== 'enrolled') {
                        $error = "Only currently enrolled students can access the evaluation system. Your status: " . ucfirst($enrollment_status);
                        
                        // Log denied access
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                        $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                       // Replace with prepared statement
                        $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                        $log_stmt->bind_param("isss", $user['id'], 'login_denied_not_enrolled', $ip, $agent);
                        $log_stmt->execute();
                        
                        // Destroy session
                        session_destroy();
                        return;
                    }
                }
                
                // Login successful - redirect based on role
                if ($user['role'] === 'admin') {
                    header("Location: admin/index.php");
                } else {
                    header("Location: student/index.php");
                }
                exit();
            } else {
                $error = "Invalid username or password!";
                
                // Log failed attempt
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                $conn->query("INSERT INTO user_logs (user_id, action, ip_address, user_agent) VALUES (NULL, 'login_failed', '$ip', '$agent')");
            }
        }
    }
    
    // Handle registration
    if (isset($_POST['register'])) {
        $username = trim($_POST['reg_username']);
        $password = $_POST['reg_password'];
        $confirm_password = $_POST['reg_confirm_password'];
        $email = trim($_POST['reg_email']);
        $student_id = trim($_POST['reg_student_id']);
        $fullname = trim($_POST['reg_fullname']);
        
        // Validate inputs
        if (empty($username) || empty($password) || empty($confirm_password) || empty($email) || empty($student_id) || empty($fullname)) {
            $error = "All fields are required!";
            $showRegister = true;
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long!";
            $showRegister = true;
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match!";
            $showRegister = true;
        } elseif (!$functions->validateEmail($email)) {
            $error = "Please enter a valid email address!";
            $showRegister = true;
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $error = "Username must be 3-20 characters (letters, numbers, underscore only)!";
            $showRegister = true;
        } else {
            // Check if username already exists
            $check_username_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_username_stmt->bind_param("s", $username);
            $check_username_stmt->execute();
            
            if ($check_username_stmt->get_result()->num_rows > 0) {
                $error = "Username already exists!";
                $showRegister = true;
                $check_username_stmt->close();
            } else {
                $check_username_stmt->close();
                
                // Check if email already exists
                $check_email_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $check_email_stmt->bind_param("s", $email);
                $check_email_stmt->execute();
                
                if ($check_email_stmt->get_result()->num_rows > 0) {
                    $error = "Email already registered!";
                    $showRegister = true;
                    $check_email_stmt->close();
                } else {
                    $check_email_stmt->close();
                    
                    // Check if student ID already exists
                    $check_student_stmt = $conn->prepare("SELECT id FROM users WHERE student_id = ?");
                    $check_student_stmt->bind_param("s", $student_id);
                    $check_student_stmt->execute();
                    
                    if ($check_student_stmt->get_result()->num_rows > 0) {
                        $error = "Student ID already registered!";
                        $showRegister = true;
                        $check_student_stmt->close();
                    } else {
                        $check_student_stmt->close();
                        
                        // Register the user as student with enrolled status (US-16)
                        $result = $functions->registerUserWithEnrollment($username, $password, $email, 'student', $student_id, $fullname);
                        
                        if ($result['success']) {
                            $success = "Registration successful! Please login with your credentials.";
                            $showRegister = false; // Switch to login form
                            
                            // Pre-fill the username in login form
                            $_POST['username'] = $username;
                            
                            // Log registration
                            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                            $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                            $conn->query("INSERT INTO user_logs (user_id, action, ip_address, user_agent) VALUES ({$result['user_id']}, 'registration', '$ip', '$agent')");
                        } else {
                            $error = $result['message'];
                            $showRegister = true;
                        }
                    }
                }
            }
        }
    }
}

// Check if showing registration form from link
if (isset($_GET['register'])) {
    $showRegister = true;
}

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    $user = $auth->getCurrentUser();
    if ($user['role'] === 'admin') {
        header("Location: admin/index.php");
    } else {
        header("Location: student/index.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EVSU-OCC - Evaluation System</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .evsu-header {
            background: linear-gradient(to right,rgb(144, 13, 13), #A52A2A);
            color: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-container img {
            height: 60px;
            width: auto;
        }
        
        .logo-container h1 {
            font-size: 1.8rem;
            margin: 0;
            color: white;
        }
        
        .subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        /* Main Container */
        .main-container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex: 1;
            padding: 20px;
        }
        
        /* Auth Card */
        .auth-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }
        
        /* Tabs */
        .auth-tabs {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .auth-tab {
            flex: 1;
            padding: 18px;
            background: none;
            border: none;
            font-size: 1.1rem;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            text-align: center;
        }
        
        .auth-tab.active {
            color:rgb(209, 9, 9);
            background: white;
        }
        
        .auth-tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: #8B0000;
        }
        
        .auth-tab:hover:not(.active) {
            background: #e9ecef;
            color: #555;
        }
        
        /* Form Container */
        .form-container {
            padding: 35px;
        }
        
        .form-title {
            color: #333;
            margin-bottom: 25px;
            text-align: center;
            font-size: 1.4rem;
        }
        
        /* Forms */
        .auth-form {
            display: none;
        }
        
        .auth-form.active {
            display: block;
            animation: slideIn 0.4s ease;
        }
        
        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 14px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #fafafa;
        }
        
        .form-control:focus {
            outline: none;
            border-color:rgb(205, 24, 24);
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1);
        }
        
        /* Password Toggle */
        .password-container {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
        }
        
        /* Alerts */
        .alert {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 0.95rem;
            animation: fadeIn 0.5s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-danger {
            background: #f8d7da;
            color:rgb(207, 13, 33);
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            margin: 20px 0;
        }
        
        .checkbox-group input {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        /* Buttons */
        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(to right, #8B0000, #A52A2A);
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #A52A2A, #8B0000);
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(139, 0, 0, 0.25);
        }
        
        .btn-success {
            background: linear-gradient(to right, #28a745, #20c997);
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(to right, #218838, #1ba87e);
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(40, 167, 69, 0.25);
        }
        
        /* Links */
        .form-link {
            color: #8B0000;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }
        
        .form-link:hover {
            color: #A52A2A;
            text-decoration: underline;
        }
        
        /* Footer Links */
        .form-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 0.9rem;
            color: #666;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .auth-card {
                max-width: 100%;
                margin: 10px;
            }
            
            .form-container {
                padding: 25px;
            }
            
            .auth-tab {
                padding: 15px;
                font-size: 1rem;
            }
            
            .form-control {
                padding: 12px 15px;
            }
            
            .btn {
                padding: 14px;
                font-size: 1rem;
            }
        }
        
        @media (max-width: 480px) {
            .form-container {
                padding: 20px;
            }
            
            .auth-tab {
                padding: 12px;
                font-size: 0.95rem;
            }
        }
        
        /* Password Strength */
        .password-strength {
            margin-top: 8px;
        }
        
        .strength-text {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 5px;
            color: #666;
        }
        
        .strength-bar {
            height: 6px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s, background-color 0.3s;
            border-radius: 3px;
        }
        
        /* Form Hint */
        .form-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
            display: block;
        }
        
        /* Terms Notice */
        .terms-notice {
            background-color: #f8f9fa;
            border-left: 4px solid #8B0000;
            padding: 12px 15px;
            margin: 20px 0;
            border-radius: 4px;
            font-size: 0.85rem;
            color: #555;
        }
        
        .terms-notice i {
            color: #8B0000;
            margin-right: 8px;
        }
        
        .terms-notice a {
            color: #8B0000;
            text-decoration: none;
            font-weight: 600;
        }
        
        .terms-notice a:hover {
            text-decoration: underline;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            margin-top: 30px;
            padding: 20px 0;
            border-top: 1px solid #eee;
            background: #f8f9fa;
            width: 100%;
        }
        
        .footer-links {
            margin-bottom: 15px;
        }
        
        .footer-links a {
            color: #555;
            text-decoration: none;
            margin: 0 10px;
            font-size: 0.9rem;
        }
        
        .footer-links a:hover {
            color: #8B0000;
            text-decoration: underline;
        }
        
        .secure-login {
            display: block;
            margin-top: 10px;
            color: #777;
            font-size: 0.8rem;
        }
        
        .secure-login i {
            color: #28a745;
            margin-right: 5px;
        }

        /* System Status Banner */
        .system-status {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .system-status i {
            color: #2196f3;
            font-size: 1.2rem;
        }
        
        /* Enrollment Notice */
        .enrollment-notice {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin: 15px 0;
            border-radius: 4px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .enrollment-notice i {
            color: #856404;
            font-size: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="evsu-header">
        <div class="header-container">
            <div class="logo-container">
                <img src="images/EVSU_Official_Logo.png" alt="EVSU Logo" onerror="this.src='https://via.placeholder.com/60x60?text=EVSU'">
                <div>
                    <h1>EVSU-OCC</h1>
                    <div class="subtitle">Evaluation Survey System - Registrar's Office</div>
                </div>
            </div>
        </div>
    </div>

    <div class="main-container">
        <div class="auth-card">
            <!-- System Status -->
            <div class="system-status">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Registrar's Office Evaluation System</strong><br>
                    <small>Services: TOR, Certification, Enrollment, Diploma, Authentication</small>
                </div>
            </div>

            <!-- Tabs for Login/Register -->
            <div class="auth-tabs">
                <button class="auth-tab <?php echo !$showRegister ? 'active' : ''; ?>" 
                        type="button" onclick="showForm('login')">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
                <button class="auth-tab <?php echo $showRegister ? 'active' : ''; ?>" 
                        type="button" onclick="showForm('register')">
                    <i class="fas fa-user-plus"></i> Register
                </button>
            </div>
            
            <div class="form-container">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> 
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> 
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Login Form -->
                <form method="POST" action="" class="auth-form <?php echo !$showRegister ? 'active' : ''; ?>" id="loginForm">
                    <h2 class="form-title">EVSU Evaluation Portal</h2>
                    <input type="hidden" name="login" value="1">
                    
                    <div class="enrollment-notice">
                        <i class="fas fa-id-card"></i>
                        <div>
                            <strong>For Students:</strong> Only currently enrolled students can access the system.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" name="username" class="form-control" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               placeholder="Enter your username" autocomplete="username">
                    </div>
                    
                    <div class="form-group">
                        <label for="loginPassword" class="form-label">Password</label>
                        <div class="password-container">
                            <input type="password" id="loginPassword" name="password" class="form-control" required 
                                   placeholder="Enter your password" autocomplete="current-password">
                            <button type="button" class="password-toggle" onclick="togglePassword('loginPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                    
                    <!-- Terms Notice -->
                    <div class="terms-notice">
                        <i class="fas fa-exclamation-circle"></i> By using this service, you understood and agree to the<br>
                        <a href="terms.php">EVSU Online Services Terms of Use</a> and <a href="privacy.php">Privacy Statement</a>
                        <br>
                        <small class="secure-login">
                            <i class="fas fa-shield-alt"></i> Secure Login | v2.0
                        </small>
                    </div>
                    
                    <div class="form-footer">
                        <a href="forgot_password.php" class="form-link">
                            <i class="fas fa-key"></i> Forgot Password?
                        </a>
                        <div style="margin-top: 10px;">
                            Don't have an account? 
                            <a href="javascript:void(0)" onclick="showForm('register')" class="form-link">
                                Register here
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- Registration Form -->
                <form method="POST" action="" class="auth-form <?php echo $showRegister ? 'active' : ''; ?>" id="registerForm">
                    <h2 class="form-title">Create Account</h2>
                    <input type="hidden" name="register" value="1">
                    
                    <div class="enrollment-notice">
                        <i class="fas fa-id-card"></i>
                        <div>
                            <strong>Note:</strong> You will be automatically set as "Enrolled" upon registration.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="reg_student_id" class="form-label">Student ID Number *</label>
                        <input type="text" id="reg_student_id" name="reg_student_id" class="form-control" required 
                               value="<?php echo isset($_POST['reg_student_id']) ? htmlspecialchars($_POST['reg_student_id']) : ''; ?>"
                               placeholder="e.g., 2023-12345">
                        <span class="form-hint">Enter your official EVSU student ID</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="reg_fullname" class="form-label">Full Name *</label>
                        <input type="text" id="reg_fullname" name="reg_fullname" class="form-control" required 
                               value="<?php echo isset($_POST['reg_fullname']) ? htmlspecialchars($_POST['reg_fullname']) : ''; ?>"
                               placeholder="Your complete name">
                    </div>
                    
                    <div class="form-group">
                        <label for="reg_email" class="form-label">Email Address *</label>
                        <input type="email" id="reg_email" name="reg_email" class="form-control" required 
                               value="<?php echo isset($_POST['reg_email']) ? htmlspecialchars($_POST['reg_email']) : ''; ?>"
                               placeholder="your.email@evsu.edu.ph">
                        <span class="form-hint">Use your EVSU email address if available</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="reg_username" class="form-label">Username *</label>
                        <input type="text" id="reg_username" name="reg_username" class="form-control" required 
                               value="<?php echo isset($_POST['reg_username']) ? htmlspecialchars($_POST['reg_username']) : ''; ?>"
                               placeholder="Choose a username">
                        <span class="form-hint">3-20 characters, letters, numbers and underscore only</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="regPassword" class="form-label">Password *</label>
                        <div class="password-container">
                            <input type="password" id="regPassword" name="reg_password" class="form-control" required 
                                   placeholder="Create a strong password" minlength="8"
                                   oninput="updatePasswordStrength(this.value)">
                            <button type="button" class="password-toggle" onclick="togglePassword('regPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength"></div>
                        <span class="form-hint">Minimum 8 characters with letters and numbers</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="regConfirmPassword" class="form-label">Confirm Password *</label>
                        <div class="password-container">
                            <input type="password" id="regConfirmPassword" name="reg_confirm_password" class="form-control" required 
                                   placeholder="Re-enter your password" minlength="8">
                            <button type="button" class="password-toggle" onclick="togglePassword('regConfirmPassword', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">
                            I agree to the <a href="terms.php" class="form-link" target="_blank">Terms</a> and 
                            <a href="privacy.php" class="form-link" target="_blank">Privacy Policy</a>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                    
                    <!-- Terms Notice -->
                    <div class="terms-notice">
                        <i class="fas fa-exclamation-circle"></i> By using this service, you understood and agree to the<br>
                        <a href="terms.php">EVSU Online Services Terms of Use</a> and <a href="privacy.php">Privacy Statement</a>
                        <br>
                        <small class="secure-login">
                            <i class="fas fa-shield-alt"></i> Secure Registration | v2.0
                        </small>
                    </div>
                    
                    <div class="form-footer">
                        Already have an account? 
                        <a href="javascript:void(0)" onclick="showForm('login')" class="form-link">
                            Sign in here
                        </a>
                        <div style="margin-top: 10px; font-size: 0.8rem; color: #888;">
                            <i class="fas fa-info-circle"></i>
                            This system is for EVSU-OCC students only.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-links">
            <a href="about.php">About</a> | 
            <a href="contact.php">Contact</a> | 
            <a href="privacy.php">Privacy</a> | 
            <a href="services.php">Registrar Services</a>
        </div>
        <p style="margin-top: 15px; font-size: 0.85rem; color: #777;">
            © <?php echo date('Y'); ?> Eastern Visayas State University. All rights reserved.
        </p>
    </div>

    <script>
        // Form switching functionality
        function showForm(formType) {
            // Update active tab
            const tabs = document.querySelectorAll('.auth-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            if (formType === 'login') {
                tabs[0].classList.add('active');
                // Update URL without page reload
                history.replaceState(null, '', window.location.pathname);
            } else {
                tabs[1].classList.add('active');
                // Update URL without page reload
                history.replaceState(null, '', window.location.pathname + '?register');
            }
            
            // Show selected form
            document.getElementById('loginForm').classList.remove('active');
            document.getElementById('registerForm').classList.remove('active');
            document.getElementById(formType + 'Form').classList.add('active');
            
            // Clear any existing alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
            
            // Focus on first input
            setTimeout(() => {
                const form = document.getElementById(formType + 'Form');
                const firstInput = form.querySelector('input:not([type="hidden"])');
                if (firstInput) firstInput.focus();
            }, 100);
        }
        
        // Password visibility toggle
        function togglePassword(passwordId, button) {
            const passwordField = document.getElementById(passwordId);
            const icon = button.querySelector('i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Password strength calculator
        function updatePasswordStrength(password) {
            const strengthDiv = document.getElementById('passwordStrength');
            if (!strengthDiv) return;
            
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength += 1;
            
            // Lowercase check
            if (/[a-z]/.test(password)) strength += 1;
            
            // Uppercase check
            if (/[A-Z]/.test(password)) strength += 1;
            
            // Numbers check
            if (/\d/.test(password)) strength += 1;
            
            // Special characters check
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            // Determine strength level
            let level = "";
            let color = "#e74c3c";
            let width = "20%";
            
            switch(strength) {
                case 1:
                    level = "Very Weak";
                    color = "#e74c3c";
                    width = "20%";
                    break;
                case 2:
                    level = "Weak";
                    color = "#e67e22";
                    width = "40%";
                    break;
                case 3:
                    level = "Fair";
                    color = "#f1c40f";
                    width = "60%";
                    break;
                case 4:
                    level = "Good";
                    color = "#2ecc71";
                    width = "80%";
                    break;
                case 5:
                    level = "Excellent";
                    color = "#27ae60";
                    width = "100%";
                    break;
                default:
                    level = "Very Weak";
                    color = "#e74c3c";
                    width = "0%";
            }
            
            // Update display
            strengthDiv.innerHTML = `
                <div class="strength-text">Password Strength: <span style="color: ${color}">${level}</span></div>
                <div class="strength-bar">
                    <div class="strength-fill" style="width: ${width}; background-color: ${color};"></div>
                </div>
            `;
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const registerForm = document.getElementById('registerForm');
            
            // Initialize password strength if on register form
            if (registerForm.classList.contains('active')) {
                const regPassword = document.getElementById('regPassword');
                if (regPassword && regPassword.value) {
                    updatePasswordStrength(regPassword.value);
                }
            }
            
            // Registration form validation
            if (registerForm) {
                registerForm.addEventListener('submit', function(e) {
                    const password = document.getElementById('regPassword').value;
                    const confirmPassword = document.getElementById('regConfirmPassword').value;
                    const terms = document.getElementById('terms');
                    
                    // Check password match
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        alert('Passwords do not match!');
                        document.getElementById('regConfirmPassword').focus();
                        return false;
                    }
                    
                    // Check password strength
                    if (password.length < 8) {
                        e.preventDefault();
                        alert('Password must be at least 8 characters long!');
                        return false;
                    }
                    
                    // Check terms agreement
                    if (!terms.checked) {
                        e.preventDefault();
                        alert('You must agree to the terms and conditions');
                        return false;
                    }
                    
                    return true;
                });
            }
        });
        
        // Clear alerts when user starts typing
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', function() {
                const alert = this.closest('.auth-card').querySelector('.alert');
                if (alert) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        });
    </script>
</body>
</html>