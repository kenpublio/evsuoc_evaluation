<?php
require_once 'config.php';

class Functions {
    private $conn;
    
    public function __construct() {
        global $conn;
        $this->conn = $conn;
    }
    
    
    // ========== DATABASE TABLE CHECKS ==========
    
    /**
     * Check if table exists
     */

    /**
 * Register user with enrollment status (US-16)
 */


/**
 * Get recent evaluations for dashboard
 */
// Removed duplicate declaration of getRecentEvaluations to avoid redeclaration error.

/**
 * Get system summary statistics
 */
public function getSystemSummary() {
    $conn = $this->conn;
    $summary = [];
    
    try {
        // Active offices
        $result = $conn->query("SELECT COUNT(*) as count FROM offices WHERE status = 'active'");
        $summary['active_offices'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Inactive offices
        $result = $conn->query("SELECT COUNT(*) as count FROM offices WHERE status != 'active'");
        $summary['inactive_offices'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Total survey questions
        $result = $conn->query("SELECT COUNT(*) as count FROM survey_questions WHERE is_active = 1");
        $summary['total_questions'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Overall rating
        $result = $conn->query("SELECT AVG(rating) as avg FROM responses WHERE rating IS NOT NULL");
        $summary['overall_rating'] = $result ? round($result->fetch_assoc()['avg'], 1) : 0;
        
        // New students this week
        $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $summary['new_students_week'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Evaluations this week
        $result = $conn->query("SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as count FROM responses WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $summary['evaluations_week'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Active users this week
        $result = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM responses WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $summary['active_users_week'] = $result ? $result->fetch_assoc()['count'] : 0;
        
    } catch (Exception $e) {
        error_log("Error in getSystemSummary: " . $e->getMessage());
        $summary = [
            'active_offices' => 0,
            'inactive_offices' => 0,
            'total_questions' => 0,
            'overall_rating' => 0,
            'new_students_week' => 0,
            'evaluations_week' => 0,
            'active_users_week' => 0
        ];
    }
    
    return $summary;
}
public function registerUserWithEnrollment($username, $password, $email, $role = 'student', $student_id = '', $fullname = '') {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        // Check if username already exists
        $check_stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            return array('success' => false, 'message' => 'Username or email already exists');
        }
        
        // Check if student_id already exists if provided
        if (!empty($student_id)) {
            $check_student = $this->conn->prepare("SELECT id FROM users WHERE student_id = ?");
            $check_student->bind_param("s", $student_id);
            $check_student->execute();
            if ($check_student->get_result()->num_rows > 0) {
                return array('success' => false, 'message' => 'Student ID already exists');
            }
            $check_student->close();
        }
        
        // Insert new user with enrollment_status = 'enrolled' (US-16)
        $stmt = $this->conn->prepare("INSERT INTO users (username, password, email, role, student_id, fullname, is_active, enrollment_status, last_enrolled) VALUES (?, ?, ?, ?, ?, ?, 1, 'enrolled', CURDATE())");
        $stmt->bind_param("ssssss", $username, $hashed_password, $email, $role, $student_id, $fullname);
        
        if ($stmt->execute()) {
            $user_id = $stmt->insert_id;
            $this->logUserAction($user_id, 'registration');
            
            return array('success' => true, 'message' => 'Registration successful', 'user_id' => $user_id);
        } else {
            return array('success' => false, 'message' => 'Registration failed: ' . $stmt->error);
        }
    } catch (Exception $e) {
        return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
    }
}
    private function tableExists($table_name) {
        $result = $this->conn->query("SHOW TABLES LIKE '{$table_name}'");
        return $result && $result->num_rows > 0;
    }
    
    /**
     * Check if column exists in table
     */
    private function columnExists($table_name, $column_name) {
        $result = $this->conn->query("SHOW COLUMNS FROM {$table_name} LIKE '{$column_name}'");
        return $result && $result->num_rows > 0;
    }
    
    // ========== REGISTRAR OFFICE FUNCTIONS ==========
    
    /**
     * Get Registrar office (single office only)
     * Creates Registrar office if it doesn't exist
     */
    /**
 * Get Registrar office (single office only)
 * Creates Registrar office if it doesn't exist
 */
public function getRegistrarOffice() {
    // Check if offices table exists
    if (!$this->tableExists('offices')) {
        $this->createOfficesTable();
    }
    
    // Try to find existing registrar office - FIXED: Use LIMIT 1
    $result = $this->conn->query("SELECT * FROM offices WHERE LOWER(name) LIKE '%registrar%' AND status = 'active' LIMIT 1");
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // If not found, create it
    $name = "Registrar's Office";
    $description = "Office of the University Registrar - Student Records, TOR, Certifications, and Enrollment";
    
    $stmt = $this->conn->prepare("INSERT INTO offices (name, description, status) VALUES (?, ?, 'active')");
    $stmt->bind_param("ss", $name, $description);
    $stmt->execute();
    $id = $stmt->insert_id;
    
    // Create default survey questions for Registrar
    $this->createRegistrarQuestions($id);
    
    return [
        'id' => $id, 
        'name' => $name, 
        'description' => $description,
        'status' => 'active'
    ];
}
    
    /**
     * Create default survey questions for Registrar
     */
    private function createRegistrarQuestions($office_id) {
        if (!$this->tableExists('survey_questions')) {
            $this->createSurveyQuestionsTable();
        }
        
        // First, delete any existing questions for this office to avoid duplicates
        $delete = $this->conn->prepare("DELETE FROM survey_questions WHERE office_id = ?");
        $delete->bind_param("i", $office_id);
        $delete->execute();
        
        $questions = [
            ['How satisfied are you with the processing time for your request?', 1, 'service'],
            ['How helpful and courteous were the Registrar staff?', 2, 'staff'],
            ['How clear were the instructions and requirements provided?', 3, 'information'],
            ['How would you rate the accuracy of your document/transaction?', 4, 'quality'],
            ['How convenient was the overall process?', 5, 'convenience'],
            ['How would you rate the waiting time?', 6, 'waiting'],
            ['How organized is the Registrar\'s Office?', 7, 'organization'],
            ['How likely are you to recommend our services to others?', 8, 'recommendation'],
            ['How would you rate the cleanliness of the office?', 9, 'facility'],
            ['Overall, how satisfied are you with the Registrar\'s Office?', 10, 'overall']
        ];
        
        foreach ($questions as $q) {
            // Check if question already exists for this office
            $check = $this->conn->prepare("
                SELECT id FROM survey_questions 
                WHERE office_id = ? AND question_text = ? AND is_active = 1
            ");
            $check->bind_param("is", $office_id, $q[0]);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows == 0) {
                // Only insert if it doesn't exist
                $stmt = $this->conn->prepare("
                    INSERT INTO survey_questions (office_id, question_text, display_order, category, is_active) 
                    VALUES (?, ?, ?, ?, 1)
                ");
                $stmt->bind_param("isss", $office_id, $q[0], $q[1], $q[2]);
                $stmt->execute();
            }
        }
    }
    
    /**
     * Get Registrar statistics only
     */
    public function getRegistrarStats() {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        $stats = [];
        
        // 1. Total students
        if ($this->tableExists('users')) {
            $result = $this->conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND is_active = 1");
            $stats['total_students'] = $result ? $result->fetch_assoc()['total'] : 0;
        } else {
            $stats['total_students'] = 0;
        }
        
        // 2. Total evaluations for registrar
        if ($this->tableExists('responses')) {
            $result = $this->conn->query("SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as total FROM responses WHERE office_id = $office_id");
            $stats['total_evaluations'] = $result ? $result->fetch_assoc()['total'] : 0;
        } else {
            $stats['total_evaluations'] = 0;
        }
        
        // 3. Average rating for registrar
        if ($this->tableExists('responses')) {
            $result = $this->conn->query("SELECT AVG(rating) as avg FROM responses WHERE office_id = $office_id AND rating IS NOT NULL");
            $avg = $result ? $result->fetch_assoc()['avg'] : 0;
            $stats['average_rating'] = number_format((float)$avg, 1);
        } else {
            $stats['average_rating'] = '0.0';
        }
        
        // 4. Today's evaluations
        if ($this->tableExists('responses')) {
            $today = date('Y-m-d');
            $result = $this->conn->query("SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as count FROM responses WHERE office_id = $office_id AND DATE(submitted_at) = '$today'");
            $stats['evaluations_today'] = $result ? $result->fetch_assoc()['count'] : 0;
        } else {
            $stats['evaluations_today'] = 0;
        }
        
        // 5. This week's evaluations
        if ($this->tableExists('responses')) {
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $result = $this->conn->query("SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as count FROM responses WHERE office_id = $office_id AND DATE(submitted_at) >= '$week_start'");
            $stats['evaluations_week'] = $result ? $result->fetch_assoc()['count'] : 0;
        } else {
            $stats['evaluations_week'] = 0;
        }
        
        // 6. Evaluations with comments (pending review)
        if ($this->tableExists('responses')) {
            $result = $this->conn->query("SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as count FROM responses WHERE office_id = $office_id AND (answer IS NOT NULL AND answer != '')");
            $stats['pending_evaluations'] = $result ? $result->fetch_assoc()['count'] : 0;
        } else {
            $stats['pending_evaluations'] = 0;
        }
        
        // 7. Top service type
        $stats['top_service'] = $this->getTopRegistrarService($office_id);
        
        // 8. Satisfaction rate
        $stats['satisfaction_rate'] = ($stats['average_rating'] > 0) ? 
            round(($stats['average_rating'] / 5) * 100) : 0;
        
        return $stats;
    }
    
    /**
     * Get top registrar service
     */
    private function getTopRegistrarService($office_id) {
        if (!$this->tableExists('responses') || !$this->tableExists('survey_questions')) {
            return 'TOR';
        }
        
        $result = $this->conn->query("
            SELECT sq.question_text, COUNT(*) as count
            FROM responses r
            JOIN survey_questions sq ON r.question_text = sq.question_text
            WHERE r.office_id = $office_id
            GROUP BY sq.question_text
            ORDER BY count DESC
            LIMIT 1
        ");
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $text = strtolower($row['question_text']);
            
            if (strpos($text, 'tor') !== false || strpos($text, 'transcript') !== false) {
                return 'TOR';
            } elseif (strpos($text, 'certification') !== false) {
                return 'Certification';
            } elseif (strpos($text, 'enrollment') !== false) {
                return 'Enrollment';
            } elseif (strpos($text, 'diploma') !== false) {
                return 'Diploma';
            }
        }
        
        return 'TOR'; // Default
    }
    
    
    /**
     * Get recent Registrar evaluations
     */
    public function getRecentRegistrarEvaluations($limit = 10) {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        if (!$this->tableExists('responses') || !$this->tableExists('users')) {
            return array();
        }
        
        try {
            $query = "
                SELECT r.*, u.username, u.fullname, u.student_id, 
                       o.name as office_name
                FROM responses r
                JOIN users u ON r.user_id = u.id
                JOIN offices o ON r.office_id = o.id
                WHERE r.office_id = ?
                ORDER BY r.submitted_at DESC
                LIMIT ?
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ii", $office_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $evaluations = array();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $evaluations[] = $row;
                }
            }
            
            return $evaluations;
            
        } catch (Exception $e) {
            error_log("Error in getRecentRegistrarEvaluations: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Get Registrar summary
     */
    public function getRegistrarSummary() {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        $summary = [
            'tor_count' => 0,
            'certification_count' => 0,
            'enrollment_count' => 0,
            'diploma_count' => 0,
            'total_responses' => 0,
            'unique_respondents' => 0,
            'average_per_question' => []
        ];
        
        if (!$this->tableExists('responses')) {
            return $summary;
        }
        
        // Total responses
        $result = $this->conn->query("SELECT COUNT(*) as count FROM responses WHERE office_id = $office_id");
        $summary['total_responses'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Unique respondents
        $result = $this->conn->query("SELECT COUNT(DISTINCT user_id) as count FROM responses WHERE office_id = $office_id");
        $summary['unique_respondents'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        // Count by service type (based on question text)
        $service_types = [
            'tor' => ['tor', 'transcript'],
            'certification' => ['certification', 'certificate'],
            'enrollment' => ['enrollment', 'enrol'],
            'diploma' => ['diploma']
        ];
        
        foreach ($service_types as $key => $keywords) {
            $conditions = [];
            foreach ($keywords as $kw) {
                $conditions[] = "LOWER(question_text) LIKE '%$kw%'";
            }
            $where = implode(' OR ', $conditions);
            
            $result = $this->conn->query("
                SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as count 
                FROM responses 
                WHERE office_id = $office_id AND ($where)
            ");
            
            $summary[$key . '_count'] = $result ? $result->fetch_assoc()['count'] : 0;
        }
        
        // Average per question
        $result = $this->conn->query("
            SELECT question_text, AVG(rating) as avg_rating
            FROM responses
            WHERE office_id = $office_id AND rating IS NOT NULL
            GROUP BY question_text
            ORDER BY avg_rating DESC
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $summary['average_per_question'][$row['question_text']] = round($row['avg_rating'], 1);
            }
        }
        
        return $summary;
    }
    
    // ========== USER FUNCTIONS ==========
    
    /**
     * Register a new user
     */
    public function registerUser($username, $password, $email, $role = 'student', $student_id = '', $fullname = '') {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            // Check if username already exists
            $check_stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check_stmt->bind_param("ss", $username, $email);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                return array('success' => false, 'message' => 'Username or email already exists');
            }
            
            // Check if student_id already exists if provided
            if (!empty($student_id)) {
                $check_student = $this->conn->prepare("SELECT id FROM users WHERE student_id = ?");
                $check_student->bind_param("s", $student_id);
                $check_student->execute();
                if ($check_student->get_result()->num_rows > 0) {
                    return array('success' => false, 'message' => 'Student ID already exists');
                }
                $check_student->close();
            }
            
            // Insert new user
            $stmt = $this->conn->prepare("INSERT INTO users (username, password, email, role, student_id, fullname, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("ssssss", $username, $hashed_password, $email, $role, $student_id, $fullname);
            
            if ($stmt->execute()) {
                $user_id = $stmt->insert_id;
                $this->logUserAction($user_id, 'registration');
                
                return array('success' => true, 'message' => 'Registration successful', 'user_id' => $user_id);
            } else {
                return array('success' => false, 'message' => 'Registration failed: ' . $stmt->error);
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user by ID
     */
    public function getUserById($id) {
        $stmt = $this->conn->prepare("SELECT id, username, email, role, student_id, fullname, is_active, created_at, last_login FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get user by username
     */
    public function getUserByUsername($username) {
        $stmt = $this->conn->prepare("SELECT id, username, password, email, role, student_id, fullname, is_active FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Get all users (filtered by role)
     */
    public function getAllUsers($role = null) {
        if ($role) {
            $stmt = $this->conn->prepare("SELECT id, username, email, role, student_id, fullname, is_active, created_at, last_login FROM users WHERE role = ? ORDER BY created_at DESC");
            $stmt->bind_param("s", $role);
        } else {
            $stmt = $this->conn->prepare("SELECT id, username, email, role, student_id, fullname, is_active, created_at, last_login FROM users ORDER BY created_at DESC");
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get all students (active)
     */
    public function getAllStudents() {
        return $this->getAllUsers('student');
    }
    
    /**
     * Update user role
     */
    public function updateUserRole($user_id, $role) {
        $stmt = $this->conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $role, $user_id);
        return $stmt->execute();
    }
    
    /**
     * Update user status (active/inactive)
     */
    public function updateUserStatus($user_id, $is_active) {
        $stmt = $this->conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $is_active, $user_id);
        return $stmt->execute();
    }
    
    /**
     * Update last login time
     */
    public function updateLastLogin($user_id) {
        $stmt = $this->conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        return $stmt->execute();
    }
    
    /**
     * Log user action
     */
    private function logUserAction($user_id, $action) {
        if (!$this->tableExists('user_logs')) {
            $this->createUserLogsTable();
        }
        
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Unknown';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
        
        try {
            $stmt = $this->conn->prepare("INSERT INTO user_logs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $user_id, $action, $ip, $user_agent);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to log user action: " . $e->getMessage());
        }
    }
    
    // ========== REGISTRAR EVALUATION FUNCTIONS ==========
    
    /**
     * Submit evaluation to Registrar's Office
     */
    /**
 * Submit evaluation with service type (US-02, US-03)
 */
public function submitRegistrarEvaluationWithService($user_id, $service_type, $ratings, $answers = [], $report = '') {
    try {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        // Check if survey is active
        if (!$this->isSurveyActive()) {
            return ['success' => false, 'message' => 'Survey is currently inactive.'];
        }
        
        // Check if user already evaluated today
        if ($this->hasUserEvaluatedToday($user_id, $office_id)) {
            return ['success' => false, 'message' => 'You have already submitted an evaluation today.'];
        }
        
        $this->conn->begin_transaction();
        
        // Get survey questions
        $questions = $this->getRegistrarQuestions();
        
        // Insert new ratings
        if ($this->tableExists('responses')) {
            $insert_stmt = $this->conn->prepare("
                INSERT INTO responses (user_id, office_id, service_type_id, question_text, rating, answer, submitted_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $counter = 0;
            foreach ($questions as $index => $question) {
                $rating = isset($ratings[$index]) ? $ratings[$index] : 5;
                $answer = isset($answers[$index]) ? $answers[$index] : '';
                $question_text = $question['question_text'] ?? $question;
                
                $insert_stmt->bind_param("iiisss", $user_id, $office_id, $service_type, $question_text, $rating, $answer);
                $insert_stmt->execute();
                $counter++;
            }
        }
        
        // Store report if provided
        if (!empty($report) && $this->tableExists('reports')) {
            $delete_report = $this->conn->prepare("DELETE FROM reports WHERE user_id = ? AND office_id = ?");
            $delete_report->bind_param("ii", $user_id, $office_id);
            $delete_report->execute();
            
            $report_stmt = $this->conn->prepare("INSERT INTO reports (office_id, user_id, report_text, submitted_at) VALUES (?, ?, ?, NOW())");
            $report_stmt->bind_param("iis", $office_id, $user_id, $report);
            $report_stmt->execute();
        }
        
        $this->conn->commit();
        
        // Log the evaluation
        $this->logUserAction($user_id, 'evaluation_submitted_registrar');
        
        return [
            'success' => true,
            'message' => 'Thank you for evaluating the Registrar\'s Office!',
            'count' => $counter
        ];
        
    } catch (Exception $e) {
        $this->conn->rollback();
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Check if survey is active (US-12)
 */
public function isSurveyActive() {
    $conn = $this->conn;
    $result = $conn->query("SELECT * FROM survey_availability ORDER BY id DESC LIMIT 1");
    $settings = $result->fetch_assoc();
    
    if (!$settings) {
        return true; // Default to active if no settings
    }
    
    $today = date('Y-m-d');
    return $settings['is_active'] && $today >= $settings['start_date'] && $today <= $settings['end_date'];
}
    
    /**
     * Get Registrar questions
     */
    public function getRegistrarQuestions() {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        if (!$this->tableExists('survey_questions')) {
            $this->createSurveyQuestionsTable();
            $this->createRegistrarQuestions($office_id);
        }
        
        // FIXED: Added DISTINCT to prevent duplicates
        $stmt = $this->conn->prepare("
            SELECT DISTINCT id, question_text, question_type, category, display_order, is_active 
            FROM survey_questions 
            WHERE office_id = ? AND is_active = 1 
            ORDER BY display_order, id
        ");
        $stmt->bind_param("i", $office_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $questions = [];
        $seen_questions = []; // Track seen questions to prevent duplicates
        
        while ($row = $result->fetch_assoc()) {
            // Check if we've already seen this question text
            if (!in_array($row['question_text'], $seen_questions)) {
                $questions[] = $row;
                $seen_questions[] = $row['question_text'];
            }
        }
        
        // If no questions found, create default ones
        if (empty($questions)) {
            $this->createRegistrarQuestions($office_id);
            
            // Try again
            $stmt = $this->conn->prepare("
                SELECT * FROM survey_questions 
                WHERE office_id = ? AND is_active = 1 
                ORDER BY display_order, id
            ");
            $stmt->bind_param("i", $office_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $questions[] = $row;
            }
        }
        
        return $questions;
    }
    
    /**
     * Check if user has evaluated Registrar today
     */
    public function hasUserEvaluatedToday($user_id, $office_id = null) {
        if (!$this->tableExists('responses')) {
            return false;
        }
        
        if (!$office_id) {
            $registrar = $this->getRegistrarOffice();
            $office_id = $registrar['id'];
        }
        
        $today = date('Y-m-d');
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT CONCAT(user_id, '-', DATE(submitted_at))) as count 
            FROM responses 
            WHERE user_id = ? AND office_id = ? AND DATE(submitted_at) = ?
        ");
        $stmt->bind_param("iis", $user_id, $office_id, $today);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return isset($result['count']) && $result['count'] > 0;
    }
    
    /**
     * Get user's evaluation history for Registrar
     */
    public function getUserRegistrarEvaluations($user_id) {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        if (!$this->tableExists('responses')) {
            return array();
        }
        
        $stmt = $this->conn->prepare("
            SELECT r.*, o.name as office_name
            FROM responses r
            JOIN offices o ON r.office_id = o.id
            WHERE r.user_id = ? AND r.office_id = ?
            ORDER BY r.submitted_at DESC
        ");
        $stmt->bind_param("ii", $user_id, $office_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : array();
    }
    
    // ========== DASHBOARD STATISTICS ==========
    
    /**
     * Get dashboard statistics - REGISTRAR ONLY VERSION
     */
    public function getDashboardStats() {
        return $this->getRegistrarStats();
    }
    
    /**
     * Get admin statistics - REGISTRAR ONLY VERSION
     */
    public function getAdminStats() {
        $stats = $this->getRegistrarStats();
        
        // Add admin-specific stats
        try {
            // Total admins
            if ($this->tableExists('users')) {
                $result = $this->conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'admin' AND is_active = 1");
                $stats['total_admins'] = $result ? $result->fetch_assoc()['total'] : 0;
            } else {
                $stats['total_admins'] = 0;
            }
            
            // Today's logs
            if ($this->tableExists('user_logs')) {
                $result = $this->conn->query("SELECT COUNT(*) as total FROM user_logs WHERE DATE(created_at) = CURDATE()");
                $stats['logs_today'] = $result ? $result->fetch_assoc()['total'] : 0;
            } else {
                $stats['logs_today'] = 0;
            }
            
            // Recent evaluations (last 10)
            $stats['recent_evaluations'] = $this->getRecentRegistrarEvaluations(10);
            
            // Top office (Registrar only, but keep for compatibility)
            $registrar = $this->getRegistrarOffice();
            $stats['top_office'] = array(
                'name' => $registrar['name'],
                'avg_rating' => $stats['average_rating']
            );
            
        } catch (Exception $e) {
            error_log("Error in getAdminStats: " . $e->getMessage());
            $stats['total_admins'] = 0;
            $stats['logs_today'] = 0;
            $stats['recent_evaluations'] = array();
            $stats['top_office'] = array('name' => 'Registrar\'s Office', 'avg_rating' => '0.0');
        }
        
        return $stats;
    }
    
    // ========== PASSWORD RESET FUNCTIONS ==========
    
    /**
     * Generate random token
     */
    public function generateToken() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Create password reset token
     */
    public function createPasswordResetToken($email) {
        try {
            // Check if user exists
            $stmt = $this->conn->prepare("SELECT id, username, email FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
            if (!$user) {
                return array('success' => false, 'message' => 'Email not found');
            }
            
            // Generate reset token
            $token = $this->generateToken();
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Create password_resets table if it doesn't exist
            if (!$this->tableExists('password_resets')) {
                $this->createPasswordResetsTable();
            }
            
            // Delete existing tokens for this user
            $delete_stmt = $this->conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $delete_stmt->bind_param("i", $user['id']);
            $delete_stmt->execute();
            
            // Insert new token
            $insert_stmt = $this->conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $insert_stmt->bind_param("iss", $user['id'], $token, $expires_at);
            
            if ($insert_stmt->execute()) {
                $this->logUserAction($user['id'], 'password_reset_requested');
                
                return array(
                    'success' => true,
                    'message' => 'Reset token created',
                    'token' => $token,
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'expires_at' => $expires_at
                );
            } else {
                return array('success' => false, 'message' => 'Failed to create reset token');
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate password reset token
     */
    public function validatePasswordResetToken($token) {
        try {
            if (!$this->tableExists('password_resets')) {
                return array('success' => false, 'message' => 'Password reset system not configured');
            }
            
            $stmt = $this->conn->prepare("
                SELECT pr.*, u.email, u.username 
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.token = ? AND pr.expires_at > NOW()
            ");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if (!$result) {
                return array('success' => false, 'message' => 'Invalid or expired reset token');
            }
            
            return array(
                'success' => true,
                'message' => 'Token is valid',
                'user_id' => $result['user_id'],
                'email' => $result['email'],
                'username' => $result['username'],
                'token' => $token
            );
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Reset password with token
     */
    public function resetPasswordWithToken($token, $new_password) {
        try {
            // Validate token
            $validation = $this->validatePasswordResetToken($token);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update user password
            $update_stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $validation['user_id']);
            
            if ($update_stmt->execute()) {
                // Delete used token
                $delete_stmt = $this->conn->prepare("DELETE FROM password_resets WHERE token = ?");
                $delete_stmt->bind_param("s", $token);
                $delete_stmt->execute();
                
                // Log the action
                $this->logUserAction($validation['user_id'], 'password_reset_completed');
                
                return array(
                    'success' => true,
                    'message' => 'Password reset successfully'
                );
            } else {
                return array('success' => false, 'message' => 'Failed to update password');
            }
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }
    
    // ========== SEARCH FUNCTIONS ==========
    
    /**
     * Search evaluations (Registrar only)
     */
    public function searchEvaluations($search_term, $date_from = null, $date_to = null) {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        if (!$this->tableExists('responses') || !$this->tableExists('users')) {
            return array();
        }
        
        $query = "
            SELECT r.*, u.username, u.email, u.fullname, u.student_id, o.name as office_name 
            FROM responses r
            JOIN users u ON r.user_id = u.id
            JOIN offices o ON r.office_id = o.id
            WHERE r.office_id = ?
        ";
        
        $params = array($office_id);
        $types = "i";
        
        if (!empty($search_term)) {
            $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.fullname LIKE ? OR u.student_id LIKE ? OR r.question_text LIKE ?)";
            $search_param = "%" . $this->conn->real_escape_string($search_term) . "%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= "sssss";
        }
        
        if (!empty($date_from)) {
            $query .= " AND DATE(r.submitted_at) >= ?";
            $params[] = $date_from;
            $types .= "s";
        }
        
        if (!empty($date_to)) {
            $query .= " AND DATE(r.submitted_at) <= ?";
            $params[] = $date_to;
            $types .= "s";
        }
        
        $query .= " ORDER BY r.submitted_at DESC";
        
        $stmt = $this->conn->prepare($query);
        if (!empty($params)) {
            $bind_params = array($types);
            for ($i = 0; $i < count($params); $i++) {
                $bind_params[] = &$params[$i];
            }
            call_user_func_array(array($stmt, 'bind_param'), $bind_params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : array();
    }
    
    /**
     * Get system logs
     */
    public function getSystemLogs($limit = 100) {
        if (!$this->tableExists('user_logs')) {
            return array();
        }
        
        $stmt = $this->conn->prepare("
            SELECT l.*, u.username 
            FROM user_logs l
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : array();
    }
    
    // ========== EXPORT FUNCTIONS ==========
    
    /**
     * Export evaluations to CSV (Registrar only)
     */
    public function exportEvaluationsToCSV() {
        $registrar = $this->getRegistrarOffice();
        $office_id = $registrar['id'];
        
        $evaluations = $this->searchEvaluations('');
        $filename = 'registrar_evaluations_' . date('Y-m-d_H-i-s') . '.csv';
        
        if (!$evaluations || !is_array($evaluations) || empty($evaluations)) {
            header('Content-Type: text/html; charset=utf-8');
            echo "No evaluation data available for export.";
            exit();
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
        
        // Write headers
        fputcsv($output, array('ID', 'Student', 'Student ID', 'Full Name', 'Office', 'Question', 'Rating', 'Answer/Comments', 'Submitted At'));
        
        // Write data
        foreach ($evaluations as $eval) {
            if (!is_array($eval)) continue;
            
            fputcsv($output, array(
                isset($eval['id']) ? $eval['id'] : '',
                isset($eval['username']) ? $eval['username'] : '',
                isset($eval['student_id']) ? $eval['student_id'] : '',
                isset($eval['fullname']) ? $eval['fullname'] : '',
                isset($eval['office_name']) ? $eval['office_name'] : 'Registrar\'s Office',
                isset($eval['question_text']) ? $eval['question_text'] : '',
                isset($eval['rating']) ? $eval['rating'] : '',
                isset($eval['answer']) ? $eval['answer'] : '',
                isset($eval['submitted_at']) ? $eval['submitted_at'] : ''
            ));
        }
        
        fclose($output);
        exit();
    }
    
    // ========== HELPER FUNCTIONS ==========
    
    /**
     * Sanitize input
     */
    public function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    /**
     * Validate password
     */
    public function validatePassword($password) {
        if (strlen($password) < 8) {
            return array('valid' => false, 'message' => 'Password must be at least 8 characters');
        }
        return array('valid' => true, 'message' => 'Password is strong');
    }
    
    /**
     * Check if username exists
     */
    public function usernameExists($username) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
    
    /**
     * Check if email exists
     */
    public function emailExists($email) {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
    
    /**
     * Update user profile
     */
    public function updateUserProfile($user_id, $fullname, $email) {
        $stmt = $this->conn->prepare("UPDATE users SET fullname = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $fullname, $email, $user_id);
        return $stmt->execute();
    }
    
    /**
     * Change user password
     */
    public function changePassword($user_id, $current_password, $new_password) {
        // Get current password hash
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result) {
            return array('success' => false, 'message' => 'User not found');
        }
        
        // Verify current password
        if (!password_verify($current_password, $result['password'])) {
            return array('success' => false, 'message' => 'Current password is incorrect');
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($update_stmt->execute()) {
            $this->logUserAction($user_id, 'password_changed');
            return array('success' => true, 'message' => 'Password changed successfully');
        } else {
            return array('success' => false, 'message' => 'Failed to change password');
        }
    }
    
    // ========== TABLE CREATION FUNCTIONS ==========
    
    /**
     * Create offices table if not exists
     */
    private function createOfficesTable() {
        $sql = "CREATE TABLE IF NOT EXISTS offices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            status ENUM('active', 'inactive', 'archived') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $this->conn->query($sql);
    }
    
    /**
     * Create survey_questions table if not exists
     */
    private function createSurveyQuestionsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS survey_questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            office_id INT NOT NULL,
            question_text TEXT NOT NULL,
            question_type ENUM('rating','text','yesno','multiple') DEFAULT 'rating',
            category VARCHAR(50) DEFAULT NULL,
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_office_id (office_id)
        )";
        $this->conn->query($sql);
    }
    
    /**
     * Create user_logs table if not exists
     */
    private function createUserLogsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS user_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action)
        )";
        $this->conn->query($sql);
    }
    
    /**
     * Create password_resets table if not exists
     */
    private function createPasswordResetsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token)
        )";
        $this->conn->query($sql);
    }
    
    // ========== COMPATIBILITY FUNCTIONS (keep existing function signatures) ==========
    
    /**
     * @deprecated Use getRegistrarStats() instead
     */
    public function getOfficeStatistics($office_id = null) {
        return $this->getRegistrarStats();
    }
    
    /**
     * @deprecated Use getRecentRegistrarEvaluations() instead
     */
    public function getRecentEvaluations($limit = 10, $office_id = null) {
        return $this->getRecentRegistrarEvaluations($limit);
    }
    
    /**
     * @deprecated Use submitRegistrarEvaluation() instead
     */
    public function submitEvaluation($user_id, $office_id = null, $ratings = [], $report = '') {
        if ($office_id === null) {
            return $this->submitRegistrarEvaluationWithService($user_id, null, $ratings, [], $report);
        }
        return $this->submitRegistrarEvaluationWithService($user_id, null, $ratings, [], $report);
    }
    
    /**
     * @deprecated Use getRegistrarQuestions() instead
     */
    public function getOfficeQuestions($office_id = null) {
        return $this->getRegistrarQuestions();
    }
    
    /**
     * Get evaluation count - placeholder for compatibility
     */
    public function getUserEvaluationCount($user_id) {
        $evaluations = $this->getUserRegistrarEvaluations($user_id);
        return count($evaluations);
    }
    
    /**
     * Get user last activity - placeholder for compatibility
     */
    public function getUserLastActivity($user_id) {
        $evaluations = $this->getUserRegistrarEvaluations($user_id);
        if (!empty($evaluations)) {
            return $evaluations[0]['submitted_at'];
        }
        return null;
    }
    
    /**
     * Get active sessions count - placeholder for compatibility
     */
    public function getActiveSessionsCount($user_id) {
        return 0;
    }
    
    /**
     * Reset user password - placeholder for compatibility
     */
    public function resetUserPassword($user_id, $new_password) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $hashed_password, $user_id);
        return $stmt->execute();
    }
    
    /**
     * Get all evaluations - for compatibility
     */
    public function getAllEvaluations($limit = 100) {
        return $this->searchEvaluations('', null, null);
    }
    
    /**
     * Get office performance summary - for compatibility
     */
    public function getOfficePerformanceSummary() {
        $registrar = $this->getRegistrarOffice();
        $stats = $this->getRegistrarStats();
        
        return [[
            'id' => $registrar['id'],
            'name' => $registrar['name'],
            'total_respondents' => $stats['total_evaluations'],
            'total_responses' => $stats['total_evaluations'] * 10, // Approximate
            'average_rating' => $stats['average_rating'],
            'first_evaluation' => null,
            'last_evaluation' => null
        ]];
    }
    
}



?>