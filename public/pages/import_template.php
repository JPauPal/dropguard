<?php
declare(strict_types=1);

require_once __DIR__ . "/../../app/auth.php";

require_login();
require_role(["Teacher", "Admin"]);

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=dropguard_import_template.csv");

$out = fopen("php://output", "w");
fputcsv($out, ["lrn", "name", "grade_level", "strand", "section", "gpa", "absences", "days_present", "total_school_days", "consecutive_absences"]);
fputcsv($out, ["1234-567890", "Juan Dela Cruz", "7", "", "Grade 7 - A", "85.50", "3", "47", "50", "0"]);
fputcsv($out, ["1234-567891", "Maria Santos", "11", "STEM", "Grade 11 - STEM A", "78.25", "7", "43", "50", "2"]);
fputcsv($out, ["1234-567892", "Ana Lopez", "11", "TechVoc", "Grade 11 - TechVoc A", "81.00", "4", "46", "50", "1"]);
fputcsv($out, ["", "Pedro Reyes", "10", "", "Grade 10 - A", "92.00", "1", "49", "50", "0"]);
fclose($out);
exit;
