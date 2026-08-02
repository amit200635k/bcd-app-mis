Generic Dynamic Survey Platform (State Enterprise Architecture)
1. Vision
Build a configurable, enterprise-grade survey platform where administrators can design surveys without changing the mobile application. The platform supports statewide deployments, offline data collection, GIS visualization, centralized reporting, and integration with external systems.

2. Objectives
Dynamic survey builder
Offline-first mobile application
Centralized MySQL database
MIS web portal
REST API layer
GIS visualization
Multi-level user hierarchy
Role-based access control
Approval workflow
Reporting and analytics
External database replication (MS SQL Server, Oracle, PostgreSQL, etc.)
3. Major Components
MIS Web Portal (Core PHP 8 + Bootstrap 5)
React Native Mobile App
REST API Layer
MySQL Central Database
GIS Dashboard
Public/Official Portal
Replication & Integration Service
Notification Service
Audit & Logging
Reporting Engine
4. Technology Stack
Backend
Core PHP 8.x
PDO
Composer
REST APIs
JWT Authentication
Frontend
Bootstrap 5
JavaScript
jQuery (where needed)
Mobile
React Native CLI
SQLite
Camera
GPS
Background Sync
Database
MySQL 8 (Primary)
SQL Server (Replication)
Oracle (Future)
PostgreSQL (Future)
5. System Architecture
Mobile App

|

|

REST API

|

|

Central MySQL

|

+---- MIS Portal

|

+---- GIS Dashboard

|

+---- Official Portal

|

+---- Replication Service

|

+---- SQL Server

+---- Oracle

+---- PostgreSQL

6. User Hierarchy
State Admin

↓

Department Admin

↓

District

↓

Block

↓

Panchayat

↓

Village

↓

Surveyor

Each user is restricted to assigned administrative boundaries.

7. Role-Based Access Control
Separate permissions for:

MIS Portal
Mobile Application
Permissions include:

Dashboard
User Management
Survey Builder
Reports
GIS
Approval
Export
Masters
Notifications
Mobile Sync
Photo Capture
GPS Capture
Offline Mode
8. Dynamic Survey Builder
Supports:

Textbox
Textarea
Number
Decimal
Date
Time
Dropdown
Radio
Checkbox
Multi-select
GPS
Camera
Signature
Barcode
QR Code
File Upload
Heading
Section
Auto Number
Validation:

Mandatory
Regex
Min/Max
Aadhaar
PAN
Email
Mobile
PIN Code
Conditional Logic:

Example:

IF Building Type = School

SHOW Student Count

Supports survey versioning.

9. Mobile Application
Features

Login
Offline mode
SQLite storage
Download masters
Download survey definitions
Geo-tagged photos
GPS capture
Image compression
Background synchronization
Draft surveys
Resume incomplete surveys
Push notifications
10. Synchronization
Download

Survey Forms
Masters
Permissions
Locations
Configurations
Upload

Survey Records
Photos
GPS
Logs
Conflict resolution supported.

11. Survey Workflow
Draft

↓

Submitted

↓

Block Verification

↓

District Verification

↓

State Approval

↓

Published

Rejected surveys can be sent back for re-survey.

12. GIS Module
Store:

Latitude
Longitude
Geometry
Administrative hierarchy
Visualizations:

Marker Maps
Heat Maps
Clusters
Polygons
Satellite
OSM
Filters:

District
Block
Panchayat
Village
Status
Survey Type
13. MIS Portal Modules
Dashboard
User Management
Survey Builder
Master Management
Survey Monitoring
Approval Workflow
GIS Dashboard
Reports
Export
Notifications
Settings
Audit Logs
Replication Monitoring
14. Reports
District-wise
Block-wise
Panchayat-wise
Village-wise
User-wise
Survey-wise
Daily Progress
GPS Missing
Photo Missing
Duplicate Records
Export:

Excel
CSV
PDF
15. API Modules
Authentication

Location Masters

Survey Form Download

Survey Upload

Photo Upload

Sync

Reports

Notifications

Replication

Health Check

Version Control

16. Database Design
Major entities:

Users
Roles
Permissions
Districts
Blocks
Panchayats
Villages
Survey Forms
Survey Sections
Survey Fields
Survey Options
Survey Versions
Survey Records
Survey Answers
Survey Images
GPS Logs
Notifications
Audit Logs
Sync Queue
Replication Queue
External Database Configurations
Estimated tables: 40–50.

17. Replication Framework
Application writes only to MySQL.

Replication Queue

↓

Replication Service

↓

MS SQL Server

↓

Oracle

↓

PostgreSQL

Supports retry, logging, and failure recovery.

18. Security
JWT Authentication
Password Hashing
HTTPS
Rate Limiting
Audit Logs
Device Registration
API Tokens
Session Timeout
Password Policy
Encryption for sensitive fields
19. Performance
Image compression
Lazy loading
Pagination
Indexed database
API caching
Queue-based replication
Background synchronization
20. Future Enhancements
AI validation
OCR document reading
Face verification
Drone imagery
Workflow designer
Multilingual surveys
Voice input
Digital signatures
eKYC integration
IoT integration
21. Deliverables
Core PHP 8 MIS Portal
Bootstrap 5 UI
React Native Mobile App
REST APIs
MySQL Database
GIS Dashboard
Dynamic Survey Builder
Replication Engine
Documentation
Deployment Guide
API Documentation
Database Schema
User Manual
Administrator Manual
22. Recommended Folder Structure
survey-platform/

admin/
api/
common/
config/
database/
docs/
gis/
mis/
mobile/
replication/
reports/
uploads/
logs/
23. Guiding Principle
Build once, configure forever.

The platform should support any future government survey by changing configuration rather than modifying application code.