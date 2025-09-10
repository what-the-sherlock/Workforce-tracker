Workforce Tracker: Weekly Report

This project tracks user login sessions, idle times, and generates weekly reports showing logged hours, idle hours, and productive hours.

Prerequisites
-Web Server: XAMPP (for PHP and MySQL) or any PHP+MySQL server
-Python packages: SQLAlchemy, PyMySQL

Setup Steps
1. Clone the Project
-Copy or clone the project folder to your local machine, e.g.,
-C:\xampp\htdocs\workforce-tracker\

2. Set Up MySQL Database
-Open MySQL CLI.
-Create a database:

CREATE DATABASE time_tracking;

Create tables: sessions and idle_events

Example schema:

CREATE TABLE sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50),
    machine_id VARCHAR(50),
    login_time DATETIME,
    logout_time DATETIME,
    total_idle_seconds INT DEFAULT 0
);

CREATE TABLE idle_events (
    idle_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT,
    idle_start DATETIME,
    duration_seconds INT,
    FOREIGN KEY (session_id) REFERENCES sessions(session_id)
);

3. Configure PHP Script

Open weekly_report.php.

Update database credentials:

$dbHost = '127.0.0.1';
$dbName = 'time_tracking';
$dbUser = 'root';
$dbPass = 'YOUR_MYSQL_PASSWORD';


Place weekly_report.php in your web server root:

XAMPP: C:\xampp\htdocs\workforce-tracker\weekly_report.php

4. Set Up Python Agent:

-Create and activate a virtual environment:

cd agent
python -m venv venv
venv\Scripts\activate  # Windows
source venv/bin/activate  # Linux/Mac

Install required packages:

pip install sqlalchemy pymysql

Run the agent to log a session:

python time_tracker.py --user "USERNAME" --machine "MACHINE_NAME" --db "mysql+pymysql://root:YOUR_MYSQL_PASSWORD@127.0.0.1/time_tracking"

Replace USERNAME, MACHINE_NAME, and YOUR_MYSQL_PASSWORD with your values.

5. Viewing the Report

-Open a web browser and go to: http://localhost/workforce-tracker/weekly_report.php
-Use the form to select Year and ISO Week to view the weekly report.
-The report has two sections:
	Weekly Summary: Total logged hours, idle hours, productive hours, idle %.
	Detailed Sessions: Shows each session with login/logout times, logged hours, idle hours, and productive hours.

6. Notes

-Idle % = Idle hours ÷ Logged hours.
-Productive hours = Logged hours – Idle hours.
-Make sure your Python agent and MySQL server are running to record sessions.
-All dates and times use server timezone.