# MITS Quiz Portal

A full-stack academic quiz portal designed for students, faculty, and administrators with role-based access, digital library resources, and online learning support.

## Live Demo
- **Hosted Portal:** [https://mitsquizportal.kesug.com](https://mitsquizportal.kesug.com)

## Key Features
- **Role-Based Access:** Dedicated workflows and dashboards for **Students**, **Teachers**, and **Admins**.
- **Teacher Module:** Support for digital library resources, online lectures, and student batch/class management.
- **Quiz & Evaluation Workflow:** Interactive quizzes with structured backend API handling and database persistence.
- **Account & Access Control:** User registration, status management, and login attempt protection.

## Tech Stack
- **Frontend:** HTML5, CSS3, JavaScript
- **Backend:** PHP, MySQL
- **Evaluation Engine:** C++ (`engine.cpp`)
- **Hosting & Version Control:** InfinityFree (Apache / PHP / MySQL), Git & GitHub

## Project Structure
- `index.html` — Main frontend interface
- `style.css` — Styling and layout
- `script.js` — Client-side interactivity and API calls
- `api.php` — Backend endpoints and request handling
- `config.php` — Database and system configuration (credentials masked in repository)
- `setup_admin.php` — Initial admin setup utility
- `engine.cpp` — C++ evaluation engine source
- `quiz.sql` — MySQL database schema
- `assets/mits-header.jpg` — Portal header banner asset

## Local / Server Setup Note
1. Import `quiz.sql` into a MySQL database.
2. Configure database credentials in `config.php` (keep live passwords out of public repositories).
3. Host the project files inside the web root (`htdocs` / public directory) with PHP & MySQL enabled.

## Author
**Harsh Deshbhratar**  
B.Tech (AI & Data Science), MITS Gwalior
