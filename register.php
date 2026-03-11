<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$auth = new Auth();
if ($auth->isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$functions = new Functions();
$error = '';
$success = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (!$functions->validateEmail($email)) {
            $error = "Please enter a valid email address!";
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $error = "Username must be 3-20 characters (letters, numbers, underscore only)!";
        } elseif (!preg_match('/^\d{4}-\d{5}$/', $student_id)) {
            $error = "Student ID must be in format: YYYY-XXXXX (e.g., 2023-12345)";
        } else {
            // Check if username already exists
            $check_username = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_username->bind_param("s", $username);
            $check_username->execute();
            
            if ($check_username->get_result()->num_rows > 0) {
                $error = "Username already exists!";
            } else {
                $check_username->close();
                
                // Check if email already exists
                $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $check_email->bind_param("s", $email);
                $check_email->execute();
                
                if ($check_email->get_result()->num_rows > 0) {
                    $error = "Email already registered!";
                } else {
                    $check_email->close();
                    
                    // Check if student ID already exists
                    $check_student = $conn->prepare("SELECT id FROM users WHERE student_id = ?");
                    $check_student->bind_param("s", $student_id);
                    $check_student->execute();
                    
                    if ($check_student->get_result()->num_rows > 0) {
                        $error = "Student ID already registered!";
                    } else {
                        $check_student->close();
                        
                        // Register with enrollment status (US-16)
                        $result = $functions->registerUserWithEnrollment($username, $password, $email, 'student', $student_id, $fullname);
                        
                        if ($result['success']) {
                            $success = "Registration successful! You can now login with your credentials.";
                            
                            // Log registration
                            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                            $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                            $conn->query("INSERT INTO user_logs (user_id, action, ip_address, user_agent) VALUES ({$result['user_id']}, 'registration', '$ip', '$agent')");
                            
                            // Clear form
                            $_POST = array();
                        } else {
                            $error = $result['message'];
                        }
                    }
                }
            }
        }
    }
}

// Get service types for display
$services = $conn->query("SELECT * FROM service_types WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - EVSU Evaluation System</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --evsu-red: #8B0000;
            --evsu-gold: #FFD700;
            --evsu-dark: #1a1a1a;
            --evsu-gray: #f5f5f5;
            --success-green: #28a745;
            --warning-orange: #fd7e14;
            --info-blue: #17a2b8;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .evsu-header {
            background: linear-gradient(to right, #8B0000, #A52A2A);
            color: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .back-link {
            color: var(--evsu-gold);
            text-decoration: none;
            font-weight: 600;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background 0.3s;
        }

        .back-link:hover {
            background: rgba(255,255,255,0.1);
        }

        /* Main Container */
        .main-container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex: 1;
            padding: 20px;
        }

        /* Register Card */
        .register-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }

        .card-header {
            background: linear-gradient(135deg, #8B0000 0%, #A52A2A 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }

        .card-header h2 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .card-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .card-body {
            padding: 30px;
        }

        /* System Status */
        .system-status {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .system-status h4 {
            color: #1976d2;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .service-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .service-tag {
            background: white;
            color: #1976d2;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            border: 1px solid #90caf9;
        }

        /* Enrollment Notice */
        .enrollment-notice {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .enrollment-notice i {
            color: #856404;
            font-size: 1.5rem;
        }

        .enrollment-notice p {
            color: #856404;
            font-size: 0.9rem;
            line-height: 1.5;
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

        .form-label i {
            color: #8B0000;
            width: 20px;
            margin-right: 5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: #fafafa;
        }

        .form-control:focus {
            outline: none;
            border-color: #8B0000;
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1);
        }

        .form-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 5px;
            display: block;
        }

        /* Password Container */
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

        /* Password Strength */
        .password-strength {
            margin-top: 10px;
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

        .strength-text {
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Username Availability */
        .availability-check {
            margin-top: 5px;
            font-size: 0.85rem;
        }

        .available {
            color: #28a745;
        }

        .unavailable {
            color: #dc3545;
        }

        /* Checkbox Group */
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 20px 0;
        }

        .checkbox-group input {
            margin-top: 3px;
            transform: scale(1.2);
        }

        .checkbox-group label {
            color: #555;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .checkbox-group a {
            color: #8B0000;
            text-decoration: none;
            font-weight: 600;
        }

        .checkbox-group a:hover {
            text-decoration: underline;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 14px;
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

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-flex;
            width: auto;
            padding: 10px 20px;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert i {
            font-size: 1.2rem;
        }

        /* Info Box */
        .info-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            font-size: 0.9rem;
            color: #666;
            border: 1px solid #e9ecef;
        }

        .info-box i {
            color: #8B0000;
            margin-right: 8px;
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

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .register-card {
                max-width: 100%;
                margin: 10px;
            }

            .card-body {
                padding: 20px;
            }

            .header-container {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
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
                    <div class="subtitle">Registrar's Office Evaluation System</div>
                </div>
            </div>
            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <div class="main-container">
        <div class="register-card">
            <div class="card-header">
                <h2><i class="fas fa-user-plus"></i> Create Account</h2>
                <p>Register as an EVSU Student</p>
            </div>

            <div class="card-body">
                <!-- System Status -->
                <div class="system-status">
                    <h4><i class="fas fa-building"></i> Registrar's Office Services</h4>
                    <p>You can evaluate these services after registration:</p>
                    <div class="service-tags">
                        <?php foreach ($services as $service): ?>
                            <span class="service-tag">
                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($service['name']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Enrollment Notice (US-16) -->
                <div class="enrollment-notice">
                    <i class="fas fa-id-card"></i>
                    <div>
                        <strong>Enrollment Status:</strong> You will be registered as <span style="color: #28a745; font-weight: bold;">"Enrolled"</span> automatically.<br>
                        <small>Only enrolled students can access the evaluation system.</small>
                    </div>
                </div>

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
                        <div style="margin-top: 10px;">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-sign-in-alt"></i> Go to Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$success): ?>
                    <form method="POST" action="" id="registerForm">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-id-card"></i> Student ID Number *
                            </label>
                            <input type="text" 
                                   name="reg_student_id" 
                                   id="regStudentId" 
                                   class="form-control" 
                                   placeholder="e.g., 2023-12345" 
                                   pattern="\d{4}-\d{5}"
                                   title="Format: YYYY-XXXXX (e.g., 2023-12345)"
                                   value="<?php echo isset($_POST['reg_student_id']) ? htmlspecialchars($_POST['reg_student_id']) : ''; ?>"
                                   required>
                            <span class="form-hint">Format: YYYY-XXXXX (e.g., 2023-12345)</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user"></i> Full Name *
                            </label>
                            <input type="text" 
                                   name="reg_fullname" 
                                   id="regFullname" 
                                   class="form-control" 
                                   placeholder="Your complete name"
                                   value="<?php echo isset($_POST['reg_fullname']) ? htmlspecialchars($_POST['reg_fullname']) : ''; ?>"
                                   required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i> Email Address *
                            </label>
                            <input type="email" 
                                   name="reg_email" 
                                   id="regEmail" 
                                   class="form-control" 
                                   placeholder="your.email@evsu.edu.ph"
                                   value="<?php echo isset($_POST['reg_email']) ? htmlspecialchars($_POST['reg_email']) : ''; ?>"
                                   required>
                            <span class="form-hint">Use your EVSU email address if available</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-user-circle"></i> Username *
                            </label>
                            <input type="text" 
                                   name="reg_username" 
                                   id="regUsername" 
                                   class="form-control" 
                                   placeholder="Choose a username (3-20 characters)"
                                   pattern="[a-zA-Z0-9_]{3,20}"
                                   title="3-20 characters, letters, numbers, underscore only"
                                   value="<?php echo isset($_POST['reg_username']) ? htmlspecialchars($_POST['reg_username']) : ''; ?>"
                                   onkeyup="checkUsername(this.value)"
                                   required>
                            <span class="form-hint">3-20 characters, letters, numbers, underscore only</span>
                            <div id="usernameAvailability" class="availability-check"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-lock"></i> Password *
                            </label>
                            <div class="password-container">
                                <input type="password" 
                                       name="reg_password" 
                                       id="regPassword" 
                                       class="form-control" 
                                       placeholder="Create a strong password"
                                       minlength="8"
                                       oninput="checkPasswordStrength(this.value)"
                                       required>
                                <button type="button" class="password-toggle" onclick="togglePassword('regPassword', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength" id="passwordStrength"></div>
                            <span class="form-hint">Minimum 8 characters with letters and numbers</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-lock"></i> Confirm Password *
                            </label>
                            <div class="password-container">
                                <input type="password" 
                                       name="reg_confirm_password" 
                                       id="regConfirmPassword" 
                                       class="form-control" 
                                       placeholder="Re-enter your password"
                                       minlength="8"
                                       onkeyup="checkPasswordMatch()"
                                       required>
                                <button type="button" class="password-toggle" onclick="togglePassword('regConfirmPassword', this)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div id="passwordMatch" class="availability-check"></div>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="terms" name="terms" required>
                            <label for="terms">
                                I agree to the <a href="terms.php" target="_blank">Terms of Service</a> 
                                and <a href="privacy.php" target="_blank">Privacy Policy</a>
                            </label>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" id="enrollment_confirm" name="enrollment_confirm" required>
                            <label for="enrollment_confirm">
                                I confirm that I am a currently enrolled student of EVSU-OCC.
                            </label>
                        </div>

                        <button type="submit" name="register" class="btn btn-primary" id="registerBtn">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>

                        <div class="info-box">
                            <i class="fas fa-info-circle"></i>
                            By registering, you confirm that:
                            <ul style="margin-left: 25px; margin-top: 8px;">
                                <li>You are a currently enrolled EVSU-OCC student</li>
                                <li>Your student ID is valid and active</li>
                                <li>You will use this account only for evaluation purposes</li>
                                <li>Your enrollment status will be verified upon login</li>
                            </ul>
                        </div>

                        <div style="text-align: center; margin-top: 20px;">
                            Already have an account? 
                            <a href="index.php" style="color: #8B0000; font-weight: 600; text-decoration: none;">
                                <i class="fas fa-sign-in-alt"></i> Sign in here
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-links">
            <a href="about.php">About</a> | 
            <a href="contact.php">Contact</a> | 
            <a href="privacy.php">Privacy</a> | 
            <a href="terms.php">Terms</a>
        </div>
        <p style="margin-top: 15px; font-size: 0.85rem; color: #777;">
            © <?php echo date('Y'); ?> Eastern Visayas State University - Ormoc Campus<br>
            <small>Registrar's Office Evaluation System v2.0</small>
        </p>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Check username availability via AJAX
        function checkUsername(username) {
            const availabilityDiv = document.getElementById('usernameAvailability');
            
            if (username.length < 3) {
                availabilityDiv.innerHTML = '';
                return;
            }

            // Create form data
            const formData = new FormData();
            formData.append('username', username);

            // Send AJAX request
            fetch('check_username.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.available) {
                    availabilityDiv.innerHTML = '<span class="available"><i class="fas fa-check-circle"></i> Username is available</span>';
                } else {
                    availabilityDiv.innerHTML = '<span class="unavailable"><i class="fas fa-times-circle"></i> Username is already taken</span>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Check password strength
        function checkPasswordStrength(password) {
            const strengthDiv = document.getElementById('passwordStrength');
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

            let text = '';
            let color = '';
            let width = '';

            switch(strength) {
                case 0:
                case 1:
                    text = 'Very Weak';
                    color = '#dc3545';
                    width = '20%';
                    break;
                case 2:
                    text = 'Weak';
                    color = '#fd7e14';
                    width = '40%';
                    break;
                case 3:
                    text = 'Fair';
                    color = '#ffc107';
                    width = '60%';
                    break;
                case 4:
                    text = 'Good';
                    color = '#20c997';
                    width = '80%';
                    break;
                case 5:
                    text = 'Excellent';
                    color = '#28a745';
                    width = '100%';
                    break;
            }

            strengthDiv.innerHTML = `
                <div class="strength-bar">
                    <div class="strength-fill" style="width: ${width}; background-color: ${color};"></div>
                </div>
                <div class="strength-text" style="color: ${color};">${text}</div>
            `;
        }

        // Check if passwords match
        function checkPasswordMatch() {
            const password = document.getElementById('regPassword').value;
            const confirm = document.getElementById('regConfirmPassword').value;
            const matchDiv = document.getElementById('passwordMatch');
            
            if (confirm.length === 0) {
                matchDiv.innerHTML = '';
                return;
            }
            
            if (password === confirm) {
                matchDiv.innerHTML = '<span class="available"><i class="fas fa-check-circle"></i> Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span class="unavailable"><i class="fas fa-times-circle"></i> Passwords do not match</span>';
            }
        }

        // Form validation
        document.getElementById('registerForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('regPassword').value;
            const confirm = document.getElementById('regConfirmPassword').value;
            const terms = document.getElementById('terms').checked;
            const enrollmentConfirm = document.getElementById('enrollment_confirm').checked;
            const studentId = document.getElementById('regStudentId').value;
            
            // Validate student ID format
            const studentIdPattern = /^\d{4}-\d{5}$/;
            if (!studentIdPattern.test(studentId)) {
                e.preventDefault();
                alert('Please enter a valid Student ID in format: YYYY-XXXXX (e.g., 2023-12345)');
                return false;
            }
            
            // Validate password match
            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            // Validate password strength
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
            
            // Validate terms agreement
            if (!terms) {
                e.preventDefault();
                alert('You must agree to the Terms of Service and Privacy Policy');
                return false;
            }
            
            // Validate enrollment confirmation (US-16)
            if (!enrollmentConfirm) {
                e.preventDefault();
                alert('You must confirm that you are a currently enrolled student');
                return false;
            }
            
            // Disable submit button
            const btn = document.getElementById('registerBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
            
            return true;
        });
    </script>
</body>
</html>