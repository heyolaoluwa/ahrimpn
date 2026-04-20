-- AHRIMPN Events Seed Data — Run once to populate sample events
-- Import via phpMyAdmin → Import tab, or run in MySQL CLI

INSERT INTO events (title, start_date, end_date, venue, event_type, description, reg_link, status) VALUES

('44th Annual National Conference & General Assembly',
 '2026-11-10', '2026-11-14',
 'International Conference Centre, Abuja, FCT',
 'Conference',
 'The flagship annual gathering of AHRIMPN members nationwide. This year''s conference focuses on "Digital Transformation of Health Information Systems in Nigeria." Activities include keynote addresses, technical paper presentations, workshops, the Annual General Meeting (AGM), and the awards night celebrating outstanding members.\n\nAll registered members are encouraged to attend. Conference packages include accommodation options.',
 'mem.html', 'Upcoming'),

('ICD-11 Coding Masterclass — Batch 5',
 '2026-06-09', '2026-06-11',
 'NAUTH Conference Hall, Nnewi, Anambra State',
 'Workshop',
 'An intensive 3-day hands-on workshop on ICD-11 coding standards, transitioning from ICD-10, and practical application in Nigerian hospital settings. Facilitators are certified ICD trainers from the WHO Collaborating Centre.\n\nParticipants will receive a certificate of attendance and CPD points upon completion.',
 'mem.html', 'Upcoming'),

('Electronic Medical Records (EMR) Implementation Seminar',
 '2026-07-22', '2026-07-23',
 'Federal Medical Centre, Ebute-Metta, Lagos',
 'Seminar',
 'A two-day seminar addressing the adoption, challenges, and best practices for EMR systems across Federal Medical Centres and State hospitals. Case studies from early-adopter hospitals will be presented.\n\nOpen to HIM professionals, hospital administrators, and IT personnel in the health sector.',
 'mem.html', 'Upcoming'),

('HIP Week 2026 National Symposium',
 '2026-04-21', '2026-04-21',
 'National Hospital Auditorium, Abuja, FCT',
 'Conference',
 'A one-day national symposium held during Health Information Professionals'' Week 2026. Theme: "Guardians of Health Information: Driving Healthcare Transformation Through Technology." Featuring panel discussions, paper presentations, and policy advocacy sessions.',
 'mem.html', 'Upcoming'),

('Continuous Professional Development (CPD) Webinar — Q2 2026',
 '2026-05-15', '2026-05-15',
 'Virtual (Zoom)',
 'Webinar',
 'Quarterly CPD webinar covering updates in health records legislation, data privacy regulations (NDPR compliance for health data), and advances in clinical coding. Registered members earn 3 CPD points on attendance.\n\nZoom link will be sent to registered members 48 hours before the event.',
 'mem.html', 'Upcoming'),

('North-Central Zonal Congress 2026',
 '2026-08-06', '2026-08-07',
 'Lafia, Nasarawa State',
 'Conference',
 'The biannual North-Central Zonal Congress brings together members from Nasarawa, Benue, Kogi, Niger, Plateau, and FCT chapters. Agenda includes zonal elections, chapter reports, and a one-day technical workshop on health data governance.',
 '', 'Upcoming'),

('Health Informatics & Data Analytics Training',
 '2026-09-14', '2026-09-18',
 'University of Ibadan, Ibadan, Oyo State',
 'Training',
 'A five-day intensive training programme on health informatics tools including DHIS2, OpenMRS, and basic data analytics using Excel and Power BI for health data. Designed for practising HIM professionals seeking to upskill in digital health.\n\nCertificate awarded on completion. Limited to 40 participants.',
 'mem.html', 'Upcoming'),

('South-West Zonal Congress & Workshop',
 '2026-10-08', '2026-10-09',
 'LUTH Conference Centre, Idi-Araba, Lagos',
 'Conference',
 'The South-West Zonal Congress covers Lagos, Ogun, Oyo, Osun, Ondo, and Ekiti chapters. The event includes a workshop on medico-legal aspects of health records management and emerging documentation challenges in Nigerian courts.',
 '', 'Upcoming'),

('43rd Annual National Conference — Osun 2025',
 '2025-11-12', '2025-11-15',
 'Osogbo, Osun State',
 'Conference',
 'Successfully held in Osogbo, Osun State. Theme: "Health Information Management in the Post-COVID Era: Lessons, Innovations, and the Road Ahead." Over 800 members attended across 4 days of activities including the AGM and awards ceremony.',
 '', 'Completed'),

('ICD-10 to ICD-11 Transition Workshop — Batch 4',
 '2025-09-08', '2025-09-10',
 'University College Hospital, Ibadan, Oyo State',
 'Workshop',
 'Successfully completed. A practical workshop on transitioning hospital coding practices from ICD-10-CM to ICD-11. 62 participants received certificates. Facilitated by AHRIMPN''s Technical Education Committee.',
 '', 'Completed');
