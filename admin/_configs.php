<?php
declare(strict_types=1);

function adminCrudConfig(PDO $pdo, string $key): array
{
    $schoolOptions = adminCrudOptions(
        $pdo,
        "SELECT id, CONCAT(school_code, ' — ', school_name) AS label FROM schools ORDER BY school_name LIMIT 3000"
    );
    $studentOptions = adminCrudOptions(
        $pdo,
        "SELECT st.id, CONCAT(st.student_number, ' — ', st.full_name, ' — ', s.school_code) AS label
         FROM students st JOIN schools s ON s.id=st.school_id
         ORDER BY st.full_name LIMIT 5000"
    );
    $programmeOptions = adminCrudOptions(
        $pdo,
        "SELECT id, CONCAT(code, ' — ', name) AS label FROM programmes ORDER BY name"
    );
    $eventOptions = adminCrudOptions(
        $pdo,
        "SELECT id, CONCAT(event_code, ' — ', title) AS label FROM programme_events ORDER BY start_at DESC, title"
    );
    $moduleOptions = adminCrudOptions(
        $pdo,
        "SELECT id, CONCAT(module_code, ' — ', module_name) AS label FROM learning_modules ORDER BY sequence_no"
    );

    $membershipOptions = [
        'pending_registration' => 'Pending Registration',
        'pending_payment' => 'Pending Payment',
        'registered' => 'Registered',
        'pending_activation' => 'Pending Activation',
        'active' => 'Active',
        'inactive' => 'Inactive',
        'cancelled' => 'Cancelled',
    ];

    $eventStatusOptions = [
        'draft' => 'Draft',
        'open' => 'Open',
        'closed' => 'Closed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    $configs = [];

    $configs['schools'] = [
        'key'=>'schools','title'=>'Schools','singular'=>'School','table'=>'schools','pk'=>'id','page'=>'/admin/schools.php',
        'intro'=>'Manage school profile, JPN status, referral code and contact details.',
        'fields'=>[
            'school_code'=>['label'=>'School Code','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'school_name'=>['label'=>'School Name','type'=>'text','required'=>true,'col'=>'col-md-8'],
            'school_type'=>['label'=>'School Type','type'=>'text','col'=>'col-md-4'],
            'state'=>['label'=>'State','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'district'=>['label'=>'District','type'=>'text','col'=>'col-md-4'],
            'address'=>['label'=>'Address','type'=>'textarea','rows'=>2,'col'=>'col-12'],
            'contact_name'=>['label'=>'Contact Name','type'=>'text','col'=>'col-md-4'],
            'contact_phone'=>['label'=>'Contact Phone','type'=>'text','col'=>'col-md-4'],
            'contact_email'=>['label'=>'Contact Email','type'=>'email','col'=>'col-md-4'],
            'referrer'=>['label'=>'Referrer School Code','type'=>'text','col'=>'col-md-4'],
            'school_logo'=>['label'=>'School Logo Path','type'=>'text','col'=>'col-md-4'],
            'school_image_1'=>['label'=>'School Image Path','type'=>'text','col'=>'col-md-4'],
            'jpn'=>['label'=>'JPN Approved','type'=>'checkbox','default'=>0,'col'=>'col-md-3'],
            'unsubscribed'=>['label'=>'Email Unsubscribed','type'=>'checkbox','default'=>0,'col'=>'col-md-3'],
        ],
        'stats'=>[
            ['label'=>'Total Schools','sql'=>'SELECT COUNT(*) FROM schools','icon'=>'bi-building'],
            ['label'=>'Pahang Schools','sql'=>"SELECT COUNT(*) FROM schools WHERE LOWER(TRIM(state))='pahang'",'icon'=>'bi-geo-alt'],
            ['label'=>'JPN Approved','sql'=>'SELECT COUNT(*) FROM schools WHERE jpn=1','icon'=>'bi-patch-check'],
            ['label'=>'With Students','sql'=>'SELECT COUNT(DISTINCT school_id) FROM students','icon'=>'bi-people'],
        ],
        'list_sql'=>"SELECT s.id,s.school_code,s.school_name,s.state,s.district,s.contact_email,s.jpn,
                           (SELECT COUNT(*) FROM students st WHERE st.school_id=s.id) AS student_count
                    FROM schools s ORDER BY s.school_name",
        'columns'=>[
            ['key'=>'school_code','label'=>'Code'],
            ['key'=>'school_name','label'=>'School'],
            ['key'=>'state','label'=>'State'],
            ['key'=>'district','label'=>'District'],
            ['key'=>'student_count','label'=>'Students'],
            ['key'=>'contact_email','label'=>'Contact Email'],
            ['key'=>'jpn','label'=>'JPN','html'=>true,'format'=>fn($v)=>$v ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>'],
        ],
    ];

    $configs['teachers'] = [
        'key'=>'teachers','title'=>'Teachers','singular'=>'Teacher','table'=>'teachers','pk'=>'id','page'=>'/admin/teachers.php',
        'intro'=>'Manage teachers and their assigned schools.',
        'fields'=>[
            'school_id'=>['label'=>'School','type'=>'select','required'=>true,'options'=>$schoolOptions,'col'=>'col-md-6'],
            'full_name'=>['label'=>'Full Name','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'identity_number'=>['label'=>'Identity Number','type'=>'text','col'=>'col-md-4'],
            'position'=>['label'=>'Position','type'=>'text','col'=>'col-md-4'],
            'phone'=>['label'=>'Phone','type'=>'text','col'=>'col-md-4'],
            'email'=>['label'=>'Email','type'=>'email','col'=>'col-md-6'],
            'email_verified_at'=>['label'=>'Email Verified At','type'=>'datetime-local','col'=>'col-md-6'],
        ],
        'stats'=>[
            ['label'=>'Total Teachers','sql'=>'SELECT COUNT(*) FROM teachers','icon'=>'bi-person-badge'],
            ['label'=>'Verified Email','sql'=>'SELECT COUNT(*) FROM teachers WHERE email_verified_at IS NOT NULL','icon'=>'bi-envelope-check'],
            ['label'=>'Registered This Month','sql'=>"SELECT COUNT(*) FROM teachers WHERE created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",'icon'=>'bi-calendar-check'],
            ['label'=>'Schools Covered','sql'=>'SELECT COUNT(DISTINCT school_id) FROM teachers','icon'=>'bi-building'],
        ],
        'list_sql'=>"SELECT t.id,t.full_name,t.email,t.position,t.email_verified_at,t.created_at,s.school_code,s.school_name
                    FROM teachers t JOIN schools s ON s.id=t.school_id ORDER BY t.created_at DESC",
        'columns'=>[
            ['key'=>'full_name','label'=>'Teacher'],
            ['key'=>'school_code','label'=>'School Code'],
            ['key'=>'school_name','label'=>'School'],
            ['key'=>'position','label'=>'Position'],
            ['key'=>'email','label'=>'Email'],
            ['key'=>'email_verified_at','label'=>'Verified','html'=>true,'format'=>fn($v)=>$v ? '<span class="badge text-bg-success">Verified</span>' : '<span class="badge text-bg-warning">Pending</span>'],
            ['key'=>'created_at','label'=>'Registered'],
        ],
        'order'=>[[6,'desc']],
    ];

    $configs['students'] = [
        'key'=>'students','title'=>'Students','singular'=>'Student','table'=>'students','pk'=>'id','page'=>'/admin/students.php',
        'intro'=>'Manage student details and membership status here. The separate Memberships menu has been removed.',
        'fields'=>[
            'school_id'=>['label'=>'School','type'=>'select','required'=>true,'options'=>$schoolOptions,'col'=>'col-md-6'],
            'student_number'=>['label'=>'Student Number','type'=>'text','required'=>true,'col'=>'col-md-3'],
            'full_name'=>['label'=>'Full Name','type'=>'text','required'=>true,'col'=>'col-md-3'],
            'identity_number'=>['label'=>'Identity Number','type'=>'text','col'=>'col-md-4'],
            'gender'=>['label'=>'Gender','type'=>'select','options'=>['Male'=>'Male','Female'=>'Female','Other'=>'Other'],'col'=>'col-md-4'],
            'date_of_birth'=>['label'=>'Date of Birth','type'=>'date','col'=>'col-md-4'],
            'class_name'=>['label'=>'Class','type'=>'text','col'=>'col-md-4'],
            'jersey_name'=>['label'=>'Jersey Name','type'=>'text','col'=>'col-md-4'],
            'jersey_number'=>['label'=>'Jersey Number','type'=>'text','col'=>'col-md-4'],
            'phone'=>['label'=>'Phone','type'=>'text','col'=>'col-md-4'],
            'email'=>['label'=>'Email','type'=>'email','col'=>'col-md-4'],
            'membership_status'=>['label'=>'Membership Status','type'=>'select','required'=>true,'options'=>$membershipOptions,'default'=>'pending_payment','col'=>'col-md-4'],
            'membership_next_due_at'=>['label'=>'Membership Expiry','type'=>'datetime-local','col'=>'col-md-6','help'=>'Coupon activation and paid activation both use this expiry date.'],
            'account_verified_at'=>['label'=>'Account Verified At','type'=>'datetime-local','col'=>'col-md-6'],
        ],
        'create_defaults'=>function(array $data): array {
            $data['qr_token'] = bin2hex(random_bytes(32));
            return $data;
        },
        'stats'=>[
            ['label'=>'Total Students','sql'=>'SELECT COUNT(*) FROM students','icon'=>'bi-people'],
            ['label'=>'Active Membership','sql'=>"SELECT COUNT(*) FROM students WHERE membership_status='active' AND membership_next_due_at IS NOT NULL AND membership_next_due_at>=NOW()",'icon'=>'bi-person-check'],
            ['label'=>'Pending Activation','sql'=>"SELECT COUNT(*) FROM students WHERE membership_status IN ('pending_payment','pending_activation')",'icon'=>'bi-hourglass-split'],
            ['label'=>'Expired / Inactive','sql'=>"SELECT COUNT(*) FROM students WHERE membership_status='inactive' OR (membership_next_due_at IS NOT NULL AND membership_next_due_at<NOW())",'icon'=>'bi-person-x'],
        ],
        'list_sql'=>"SELECT st.id,st.student_number,st.full_name,st.class_name,st.membership_status,st.membership_next_due_at,st.created_at,
                           s.school_code,s.school_name
                    FROM students st JOIN schools s ON s.id=st.school_id ORDER BY st.created_at DESC",
        'columns'=>[
            ['key'=>'student_number','label'=>'Student No.'],
            ['key'=>'full_name','label'=>'Student'],
            ['key'=>'school_code','label'=>'School'],
            ['key'=>'class_name','label'=>'Class'],
            ['key'=>'membership_status','label'=>'Membership','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
            ['key'=>'membership_next_due_at','label'=>'Expiry','format'=>fn($v)=>$v ? date('d M Y',strtotime((string)$v)) : '-'],
            ['key'=>'created_at','label'=>'Registered','format'=>fn($v)=>$v ? date('d M Y',strtotime((string)$v)) : '-'],
        ],
        'order'=>[[6,'desc']],
    ];

    $configs['parents'] = [
        'key'=>'parents','title'=>'Parents / Guardians','singular'=>'Parent / Guardian','table'=>'guardians','pk'=>'id','page'=>'/admin/parents.php',
        'intro'=>'Manage parent and guardian contact records used by the Parent Portal.',
        'fields'=>[
            'guardian_number'=>['label'=>'Guardian Number','type'=>'text','col'=>'col-md-4'],
            'full_name'=>['label'=>'Full Name','type'=>'text','required'=>true,'col'=>'col-md-8'],
            'identity_number'=>['label'=>'Identity Number','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'phone'=>['label'=>'Phone','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'email'=>['label'=>'Email','type'=>'email','required'=>true,'col'=>'col-md-4'],
            'email_verified_at'=>['label'=>'Email Verified At','type'=>'datetime-local','col'=>'col-md-6'],
            'phone_verified_at'=>['label'=>'Phone Verified At','type'=>'datetime-local','col'=>'col-md-6'],
        ],
        'stats'=>[
            ['label'=>'Total Parents','sql'=>'SELECT COUNT(*) FROM guardians','icon'=>'bi-person-hearts'],
            ['label'=>'Email Verified','sql'=>'SELECT COUNT(*) FROM guardians WHERE email_verified_at IS NOT NULL','icon'=>'bi-envelope-check'],
            ['label'=>'Linked to Students','sql'=>'SELECT COUNT(DISTINCT guardian_id) FROM student_guardians','icon'=>'bi-diagram-3'],
            ['label'=>'Registered This Month','sql'=>"SELECT COUNT(*) FROM guardians WHERE created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",'icon'=>'bi-calendar-check'],
        ],
        'list_sql'=>"SELECT g.id,g.guardian_number,g.full_name,g.phone,g.email,g.email_verified_at,g.created_at,
                           COUNT(DISTINCT sg.student_id) AS child_count
                    FROM guardians g LEFT JOIN student_guardians sg ON sg.guardian_id=g.id
                    GROUP BY g.id,g.guardian_number,g.full_name,g.phone,g.email,g.email_verified_at,g.created_at
                    ORDER BY g.created_at DESC",
        'columns'=>[
            ['key'=>'guardian_number','label'=>'Guardian No.'],
            ['key'=>'full_name','label'=>'Name'],
            ['key'=>'phone','label'=>'Phone'],
            ['key'=>'email','label'=>'Email'],
            ['key'=>'child_count','label'=>'Children'],
            ['key'=>'email_verified_at','label'=>'Verified','html'=>true,'format'=>fn($v)=>$v ? '<span class="badge text-bg-success">Verified</span>' : '<span class="badge text-bg-warning">Pending</span>'],
            ['key'=>'created_at','label'=>'Registered'],
        ],
        'order'=>[[6,'desc']],
    ];

    $configs['payments'] = [
        'key'=>'payments','title'=>'Payments','singular'=>'Payment','table'=>'payments','pk'=>'id','page'=>'/admin/payments.php',
        'intro'=>'Manage membership and programme payment records. Amount is stored in sen.',
        'fields'=>[
            'student_id'=>['label'=>'Student','type'=>'select','required'=>true,'options'=>$studentOptions,'col'=>'col-md-6'],
            'payment_type'=>['label'=>'Payment Type','type'=>'text','required'=>true,'default'=>'annual_membership','col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['pending'=>'Pending','paid'=>'Paid','failed'=>'Failed','refunded'=>'Refunded'],'default'=>'paid','col'=>'col-md-3'],
            'description'=>['label'=>'Description','type'=>'text','required'=>true,'default'=>'Annual membership','col'=>'col-md-6'],
            'amount_minor'=>['label'=>'Amount (sen)','type'=>'number','integer'=>true,'required'=>true,'default'=>10000,'min'=>0,'col'=>'col-md-3'],
            'currency'=>['label'=>'Currency','type'=>'text','required'=>true,'default'=>'myr','col'=>'col-md-3'],
            'paid_at'=>['label'=>'Paid At','type'=>'datetime-local','col'=>'col-md-4'],
            'stripe_payment_intent_id'=>['label'=>'Stripe Payment Intent','type'=>'text','col'=>'col-md-8'],
        ],
        'stats'=>[
            ['label'=>'Payment Records','sql'=>'SELECT COUNT(*) FROM payments','icon'=>'bi-receipt'],
            ['label'=>'Paid Payments','sql'=>"SELECT COUNT(*) FROM payments WHERE status='paid'",'icon'=>'bi-check-circle'],
            ['label'=>'Paid Revenue','sql'=>"SELECT COALESCE(SUM(amount_minor),0) FROM payments WHERE status='paid'",'icon'=>'bi-cash-stack','format'=>fn($v)=>adminCrudMoney((int)$v)],
            ['label'=>'This Month Revenue','sql'=>"SELECT COALESCE(SUM(amount_minor),0) FROM payments WHERE status='paid' AND paid_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')",'icon'=>'bi-calendar2-check','format'=>fn($v)=>adminCrudMoney((int)$v)],
        ],
        'list_sql'=>"SELECT p.id,p.payment_type,p.description,p.amount_minor,p.currency,p.status,p.paid_at,p.created_at,
                           st.student_number,st.full_name,s.school_code
                    FROM payments p JOIN students st ON st.id=p.student_id JOIN schools s ON s.id=st.school_id
                    ORDER BY p.created_at DESC",
        'columns'=>[
            ['key'=>'student_number','label'=>'Student No.'],
            ['key'=>'full_name','label'=>'Student'],
            ['key'=>'school_code','label'=>'School'],
            ['key'=>'payment_type','label'=>'Type'],
            ['key'=>'amount_minor','label'=>'Amount','format'=>fn($v,$r)=>adminCrudMoney((int)$v,(string)$r['currency'])],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
            ['key'=>'paid_at','label'=>'Paid At','format'=>fn($v)=>$v ? date('d M Y H:i',strtotime((string)$v)) : '-'],
        ],
        'order'=>[[6,'desc']],
    ];

    $baseEventFields = [
        'event_code'=>['label'=>'Event Code','type'=>'text','required'=>true,'col'=>'col-md-4'],
        'title'=>['label'=>'Title','type'=>'text','required'=>true,'col'=>'col-md-8'],
        'description'=>['label'=>'Description','type'=>'textarea','rows'=>3,'col'=>'col-12'],
        'eligibility'=>['label'=>'Eligibility','type'=>'select','required'=>true,'options'=>['school'=>'School','student'=>'Student','both'=>'School + Student'],'default'=>'both','col'=>'col-md-4'],
        'max_students_per_school'=>['label'=>'Max Students / School','type'=>'number','integer'=>true,'required'=>true,'default'=>5,'min'=>0,'col'=>'col-md-4'],
        'application_deadline'=>['label'=>'Application Deadline','type'=>'datetime-local','required'=>true,'col'=>'col-md-4'],
        'start_at'=>['label'=>'Start At','type'=>'datetime-local','col'=>'col-md-4'],
        'end_at'=>['label'=>'End At','type'=>'datetime-local','col'=>'col-md-4'],
        'delivery_mode'=>['label'=>'Delivery Mode','type'=>'text','col'=>'col-md-4'],
        'event_level'=>['label'=>'Event Level','type'=>'text','col'=>'col-md-4'],
        'organiser'=>['label'=>'Organiser','type'=>'text','required'=>true,'default'=>'Metadata Berhad','col'=>'col-md-4'],
        'venue'=>['label'=>'Venue / Platform','type'=>'text','col'=>'col-md-4'],
        'entry_fee_minor'=>['label'=>'Entry Fee (sen)','type'=>'number','integer'=>true,'required'=>true,'default'=>0,'min'=>0,'col'=>'col-md-4'],
        'currency'=>['label'=>'Currency','type'=>'text','required'=>true,'default'=>'MYR','col'=>'col-md-4'],
        'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>$eventStatusOptions,'default'=>'draft','col'=>'col-md-4'],
    ];

    $configs['events'] = [
        'key'=>'events','title'=>'Events','singular'=>'Event','table'=>'programme_events','pk'=>'id','page'=>'/admin/events.php',
        'fixed_values'=>['event_type'=>'programme'],
        'intro'=>'Manage programme events. Tournaments are managed separately under Competitions.',
        'fields'=>$baseEventFields,
        'stats'=>[
            ['label'=>'Programme Events','sql'=>"SELECT COUNT(*) FROM programme_events WHERE event_type='programme'",'icon'=>'bi-calendar-event'],
            ['label'=>'Open','sql'=>"SELECT COUNT(*) FROM programme_events WHERE event_type='programme' AND status='open'",'icon'=>'bi-door-open'],
            ['label'=>'Upcoming','sql'=>"SELECT COUNT(*) FROM programme_events WHERE event_type='programme' AND start_at>=NOW()",'icon'=>'bi-calendar2-week'],
            ['label'=>'Applications','sql'=>"SELECT COUNT(*) FROM programme_applications pa JOIN programme_events pe ON pe.id=pa.event_id WHERE pe.event_type='programme'",'icon'=>'bi-file-earmark-text'],
        ],
        'list_sql'=>"SELECT id,event_code,title,eligibility,application_deadline,start_at,delivery_mode,status
                    FROM programme_events WHERE event_type='programme' ORDER BY COALESCE(start_at,application_deadline) DESC",
        'columns'=>[
            ['key'=>'event_code','label'=>'Code'],
            ['key'=>'title','label'=>'Event'],
            ['key'=>'eligibility','label'=>'Eligibility'],
            ['key'=>'application_deadline','label'=>'Deadline','format'=>fn($v)=>$v ? date('d M Y H:i',strtotime((string)$v)) : '-'],
            ['key'=>'start_at','label'=>'Start','format'=>fn($v)=>$v ? date('d M Y H:i',strtotime((string)$v)) : '-'],
            ['key'=>'delivery_mode','label'=>'Mode'],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
        ],
        'order'=>[[4,'desc']],
    ];

    $competitionFields = $baseEventFields;
    $competitionFields['prize_distribution_mode']=['label'=>'Prize Distribution','type'=>'select','required'=>true,'options'=>['automatic'=>'Automatic %','manual'=>'Manual Amount'],'default'=>'automatic','col'=>'col-md-4'];
    $competitionFields['first_prize_percent']=['label'=>'1st Prize %','type'=>'number','default'=>50,'step'=>'0.01','col'=>'col-md-2'];
    $competitionFields['second_prize_percent']=['label'=>'2nd Prize %','type'=>'number','default'=>30,'step'=>'0.01','col'=>'col-md-2'];
    $competitionFields['third_prize_percent']=['label'=>'3rd Prize %','type'=>'number','default'=>20,'step'=>'0.01','col'=>'col-md-2'];
    $competitionFields['manual_first_prize_minor']=['label'=>'Manual 1st Prize (sen)','type'=>'number','integer'=>true,'default'=>0,'min'=>0,'col'=>'col-md-4'];
    $competitionFields['manual_second_prize_minor']=['label'=>'Manual 2nd Prize (sen)','type'=>'number','integer'=>true,'default'=>0,'min'=>0,'col'=>'col-md-4'];
    $competitionFields['manual_third_prize_minor']=['label'=>'Manual 3rd Prize (sen)','type'=>'number','integer'=>true,'default'=>0,'min'=>0,'col'=>'col-md-4'];

    $configs['competitions'] = [
        'key'=>'competitions','title'=>'Competitions','singular'=>'Competition','table'=>'programme_events','pk'=>'id','page'=>'/admin/competitions.php',
        'fixed_values'=>['event_type'=>'tournament'],
        'intro'=>'Manage tournament and competition records, entry fees and prize distribution.',
        'fields'=>$competitionFields,
        'stats'=>[
            ['label'=>'Competitions','sql'=>"SELECT COUNT(*) FROM programme_events WHERE event_type='tournament'",'icon'=>'bi-trophy'],
            ['label'=>'Open','sql'=>"SELECT COUNT(*) FROM programme_events WHERE event_type='tournament' AND status='open'",'icon'=>'bi-door-open'],
            ['label'=>'Upcoming','sql'=>"SELECT COUNT(*) FROM programme_events WHERE event_type='tournament' AND start_at>=NOW()",'icon'=>'bi-calendar2-week'],
            ['label'=>'Winners Recorded','sql'=>'SELECT COUNT(*) FROM programme_winners','icon'=>'bi-award'],
        ],
        'list_sql'=>"SELECT id,event_code,title,event_level,application_deadline,start_at,entry_fee_minor,currency,status
                    FROM programme_events WHERE event_type='tournament' ORDER BY COALESCE(start_at,application_deadline) DESC",
        'columns'=>[
            ['key'=>'event_code','label'=>'Code'],
            ['key'=>'title','label'=>'Competition'],
            ['key'=>'event_level','label'=>'Level'],
            ['key'=>'application_deadline','label'=>'Deadline','format'=>fn($v)=>$v ? date('d M Y H:i',strtotime((string)$v)) : '-'],
            ['key'=>'start_at','label'=>'Start','format'=>fn($v)=>$v ? date('d M Y H:i',strtotime((string)$v)) : '-'],
            ['key'=>'entry_fee_minor','label'=>'Fee','format'=>fn($v,$r)=>adminCrudMoney((int)$v,(string)$r['currency'])],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
        ],
        'order'=>[[4,'desc']],
    ];

    $configs['learning_modules'] = [
        'key'=>'learning_modules','title'=>'Learning Modules','singular'=>'Learning Module','table'=>'learning_modules','pk'=>'id','page'=>'/admin/learning_modules.php',
        'intro'=>'Manage the 20-module learning programme content and quiz questions.',
        'fields'=>[
            'module_code'=>['label'=>'Module Code','type'=>'text','required'=>true,'col'=>'col-md-2'],
            'sequence_no'=>['label'=>'Sequence','type'=>'number','integer'=>true,'required'=>true,'min'=>1,'col'=>'col-md-2'],
            'week_number'=>['label'=>'Week No.','type'=>'number','integer'=>true,'required'=>true,'min'=>1,'col'=>'col-md-2'],
            'day_name'=>['label'=>'Day','type'=>'text','required'=>true,'col'=>'col-md-2'],
            'week_title'=>['label'=>'Week Title','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'module_name'=>['label'=>'Module Name','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'game_title'=>['label'=>'Game Title','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'dreams_activity'=>['label'=>'Dreams Activity','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'objective'=>['label'=>'Objective','type'=>'textarea','required'=>true,'rows'=>3,'col'=>'col-md-6'],
            'quiz_title'=>['label'=>'Quiz Title','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'quiz_question'=>['label'=>'Quiz Question','type'=>'textarea','required'=>true,'rows'=>2,'col'=>'col-md-6'],
        ],
        'stats'=>[
            ['label'=>'Total Modules','sql'=>'SELECT COUNT(*) FROM learning_modules','icon'=>'bi-journal-text'],
            ['label'=>'Weeks','sql'=>'SELECT COUNT(DISTINCT week_number) FROM learning_modules','icon'=>'bi-calendar-week'],
            ['label'=>'Students Started','sql'=>'SELECT COUNT(DISTINCT student_id) FROM student_module_progress','icon'=>'bi-person-workspace'],
            ['label'=>'Completed Records','sql'=>"SELECT COUNT(*) FROM student_module_progress WHERE progress_status='completed'",'icon'=>'bi-check2-circle'],
        ],
        'list_sql'=>"SELECT id,module_code,sequence_no,week_number,week_title,day_name,module_name,game_title
                    FROM learning_modules ORDER BY sequence_no",
        'columns'=>[
            ['key'=>'sequence_no','label'=>'#'],
            ['key'=>'module_code','label'=>'Code'],
            ['key'=>'week_number','label'=>'Week'],
            ['key'=>'day_name','label'=>'Day'],
            ['key'=>'module_name','label'=>'Module'],
            ['key'=>'game_title','label'=>'Game'],
            ['key'=>'week_title','label'=>'Week Title'],
        ],
    ];

    $configs['certificates'] = [
        'key'=>'certificates','title'=>'Certificates','singular'=>'Certificate Template','table'=>'certificate_templates','pk'=>'id','page'=>'/admin/certificates.php',
        'intro'=>'Manage certificate templates for programmes, events and learning modules.',
        'fields'=>[
            'template_name'=>['label'=>'Template Name','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'recipient_scope'=>['label'=>'Recipient','type'=>'select','required'=>true,'options'=>['student'=>'Student','teacher'=>'Teacher','both'=>'Both'],'default'=>'both','col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['active'=>'Active','inactive'=>'Inactive'],'default'=>'active','col'=>'col-md-3'],
            'programme_id'=>['label'=>'Programme','type'=>'select','options'=>$programmeOptions,'col'=>'col-md-4'],
            'event_id'=>['label'=>'Event / Competition','type'=>'select','options'=>$eventOptions,'col'=>'col-md-4'],
            'module_id'=>['label'=>'Learning Module','type'=>'select','options'=>$moduleOptions,'col'=>'col-md-4'],
            'certificate_title'=>['label'=>'Certificate Title','type'=>'text','required'=>true,'default'=>'Certificate of Completion','col'=>'col-md-6'],
            'intro_text'=>['label'=>'Intro Text','type'=>'text','required'=>true,'default'=>'This certificate is proudly presented to','col'=>'col-md-6'],
            'body_text'=>['label'=>'Body Text','type'=>'textarea','rows'=>3,'col'=>'col-12'],
            'footer_text'=>['label'=>'Footer Text','type'=>'text','col'=>'col-md-6'],
            'logo_path'=>['label'=>'Logo Path','type'=>'text','col'=>'col-md-3'],
            'signature_path'=>['label'=>'Signature Path','type'=>'text','col'=>'col-md-3'],
            'signatory_name'=>['label'=>'Signatory Name','type'=>'text','col'=>'col-md-4'],
            'signatory_title'=>['label'=>'Signatory Title','type'=>'text','col'=>'col-md-4'],
            'orientation'=>['label'=>'Orientation','type'=>'select','required'=>true,'options'=>['landscape'=>'Landscape','portrait'=>'Portrait'],'default'=>'landscape','col'=>'col-md-4'],
        ],
        'stats'=>[
            ['label'=>'Templates','sql'=>'SELECT COUNT(*) FROM certificate_templates','icon'=>'bi-award'],
            ['label'=>'Active Templates','sql'=>"SELECT COUNT(*) FROM certificate_templates WHERE status='active'",'icon'=>'bi-patch-check'],
            ['label'=>'Issued Completed','sql'=>"SELECT COUNT(*) FROM issued_certificates WHERE status='completed'",'icon'=>'bi-file-earmark-check'],
            ['label'=>'Revoked','sql'=>"SELECT COUNT(*) FROM issued_certificates WHERE status='revoked'",'icon'=>'bi-file-earmark-x'],
        ],
        'list_sql'=>"SELECT ct.id,ct.template_name,ct.recipient_scope,ct.certificate_title,ct.orientation,ct.status,ct.updated_at,
                           p.name AS programme_name,pe.title AS event_title,lm.module_name
                    FROM certificate_templates ct
                    LEFT JOIN programmes p ON p.id=ct.programme_id
                    LEFT JOIN programme_events pe ON pe.id=ct.event_id
                    LEFT JOIN learning_modules lm ON lm.id=ct.module_id
                    ORDER BY ct.updated_at DESC",
        'columns'=>[
            ['key'=>'template_name','label'=>'Template'],
            ['key'=>'recipient_scope','label'=>'Recipient'],
            ['key'=>'certificate_title','label'=>'Title'],
            ['key'=>'programme_name','label'=>'Programme'],
            ['key'=>'event_title','label'=>'Event'],
            ['key'=>'orientation','label'=>'Orientation'],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
        ],
    ];

    $configs['attendance'] = [
        'key'=>'attendance','title'=>'Attendance','singular'=>'Attendance Session','table'=>'attendance_sessions','pk'=>'id','page'=>'/admin/attendance.php',
        'intro'=>'Manage student attendance sessions and credited learning hours.',
        'fields'=>[
            'student_id'=>['label'=>'Student','type'=>'select','required'=>true,'options'=>$studentOptions,'col'=>'col-md-6'],
            'attendance_month'=>['label'=>'Attendance Month','type'=>'date','required'=>true,'default'=>date('Y-m-01'),'col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['open'=>'Open','completed'=>'Completed','cancelled'=>'Cancelled'],'default'=>'open','col'=>'col-md-3'],
            'check_in_at'=>['label'=>'Check In','type'=>'datetime-local','required'=>true,'default'=>date('Y-m-d\TH:i'),'col'=>'col-md-4'],
            'check_out_at'=>['label'=>'Check Out','type'=>'datetime-local','col'=>'col-md-4'],
            'scan_method'=>['label'=>'Scan Method','type'=>'text','required'=>true,'default'=>'qr_camera','col'=>'col-md-4'],
            'actual_minutes'=>['label'=>'Actual Minutes','type'=>'number','integer'=>true,'required'=>true,'default'=>0,'min'=>0,'col'=>'col-md-3'],
            'credited_minutes'=>['label'=>'Credited Minutes','type'=>'number','integer'=>true,'required'=>true,'default'=>0,'min'=>0,'col'=>'col-md-3'],
            'notes'=>['label'=>'Notes','type'=>'textarea','rows'=>2,'col'=>'col-md-6'],
        ],
        'stats'=>[
            ['label'=>'Sessions','sql'=>'SELECT COUNT(*) FROM attendance_sessions','icon'=>'bi-clock-history'],
            ['label'=>'Completed','sql'=>"SELECT COUNT(*) FROM attendance_sessions WHERE status='completed'",'icon'=>'bi-check2-circle'],
            ['label'=>'Open Now','sql'=>"SELECT COUNT(*) FROM attendance_sessions WHERE status='open'",'icon'=>'bi-stopwatch'],
            ['label'=>'Credited Hours','sql'=>'SELECT ROUND(COALESCE(SUM(credited_minutes),0)/60,1) FROM attendance_sessions','icon'=>'bi-hourglass-split'],
        ],
        'list_sql'=>"SELECT a.id,a.attendance_month,a.check_in_at,a.check_out_at,a.credited_minutes,a.status,
                           st.student_number,st.full_name,s.school_code
                    FROM attendance_sessions a
                    JOIN students st ON st.id=a.student_id
                    JOIN schools s ON s.id=st.school_id
                    ORDER BY a.check_in_at DESC",
        'columns'=>[
            ['key'=>'student_number','label'=>'Student No.'],
            ['key'=>'full_name','label'=>'Student'],
            ['key'=>'school_code','label'=>'School'],
            ['key'=>'attendance_month','label'=>'Month'],
            ['key'=>'check_in_at','label'=>'Check In'],
            ['key'=>'credited_minutes','label'=>'Minutes'],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
        ],
        'order'=>[[4,'desc']],
    ];

    $configs['coupons'] = [
        'key'=>'coupons','title'=>'Coupons','singular'=>'Coupon','table'=>'coupons','pk'=>'id','page'=>'/admin/coupons.php',
        'intro'=>'Manage membership activation coupons and redemption limits.',
        'fields'=>[
            'code'=>['label'=>'Coupon Code','type'=>'text','required'=>true,'col'=>'col-md-4'],
            'description'=>['label'=>'Description','type'=>'text','col'=>'col-md-8'],
            'membership_years'=>['label'=>'Membership Years','type'=>'number','integer'=>true,'required'=>true,'default'=>1,'min'=>1,'col'=>'col-md-3'],
            'max_redemptions'=>['label'=>'Maximum Redemptions','type'=>'number','integer'=>true,'min'=>1,'col'=>'col-md-3'],
            'redeemed_count'=>['label'=>'Redeemed Count','type'=>'number','integer'=>true,'required'=>true,'default'=>0,'min'=>0,'col'=>'col-md-3'],
            'is_active'=>['label'=>'Active','type'=>'checkbox','default'=>1,'col'=>'col-md-3'],
            'starts_at'=>['label'=>'Starts At','type'=>'datetime-local','col'=>'col-md-6'],
            'expires_at'=>['label'=>'Expires At','type'=>'datetime-local','col'=>'col-md-6'],
        ],
        'stats'=>[
            ['label'=>'Total Coupons','sql'=>'SELECT COUNT(*) FROM coupons','icon'=>'bi-ticket-perforated'],
            ['label'=>'Active Coupons','sql'=>"SELECT COUNT(*) FROM coupons WHERE is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW())",'icon'=>'bi-ticket-detailed'],
            ['label'=>'Redemptions','sql'=>'SELECT COUNT(*) FROM coupon_redemptions','icon'=>'bi-check2-square'],
            ['label'=>'Membership Years','sql'=>'SELECT COALESCE(SUM(membership_years),0) FROM coupon_redemptions','icon'=>'bi-calendar-heart'],
        ],
        'list_sql'=>"SELECT id,code,description,membership_years,max_redemptions,redeemed_count,starts_at,expires_at,is_active
                    FROM coupons ORDER BY created_at DESC",
        'columns'=>[
            ['key'=>'code','label'=>'Code'],
            ['key'=>'description','label'=>'Description'],
            ['key'=>'membership_years','label'=>'Years'],
            ['key'=>'redeemed_count','label'=>'Redeemed'],
            ['key'=>'max_redemptions','label'=>'Max'],
            ['key'=>'expires_at','label'=>'Expires','format'=>fn($v)=>$v ? date('d M Y',strtotime((string)$v)) : 'No expiry'],
            ['key'=>'is_active','label'=>'Status','html'=>true,'format'=>fn($v)=>$v ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>'],
        ],
    ];

    $configs['reports'] = [
        'key'=>'reports','title'=>'Reports','singular'=>'Report','table'=>'admin_reports','pk'=>'id','page'=>'/admin/reports.php',
        'intro'=>'Create and maintain administrative reports and summaries.',
        'fields'=>[
            'report_code'=>['label'=>'Report Code','type'=>'text','required'=>true,'col'=>'col-md-3'],
            'title'=>['label'=>'Title','type'=>'text','required'=>true,'col'=>'col-md-6'],
            'report_type'=>['label'=>'Report Type','type'=>'select','required'=>true,'options'=>['school'=>'School','membership'=>'Student Membership','finance'=>'Finance','event'=>'Event','competition'=>'Competition','attendance'=>'Attendance','learning'=>'Learning','other'=>'Other'],'default'=>'other','col'=>'col-md-3'],
            'period_start'=>['label'=>'Period Start','type'=>'date','col'=>'col-md-3'],
            'period_end'=>['label'=>'Period End','type'=>'date','col'=>'col-md-3'],
            'status'=>['label'=>'Status','type'=>'select','required'=>true,'options'=>['draft'=>'Draft','published'=>'Published','archived'=>'Archived'],'default'=>'draft','col'=>'col-md-3'],
            'summary'=>['label'=>'Summary','type'=>'textarea','rows'=>3,'col'=>'col-12'],
            'details'=>['label'=>'Details','type'=>'textarea','rows'=>6,'col'=>'col-12'],
        ],
        'create_defaults'=>function(array $data): array {
            if (empty($data['report_code'])) {
                $data['report_code'] = 'RPT-' . date('Ymd-His');
            }
            return $data;
        },
        'stats'=>[
            ['label'=>'Reports','sql'=>'SELECT COUNT(*) FROM admin_reports','icon'=>'bi-bar-chart-line'],
            ['label'=>'Published','sql'=>"SELECT COUNT(*) FROM admin_reports WHERE status='published'",'icon'=>'bi-file-earmark-check'],
            ['label'=>'Draft','sql'=>"SELECT COUNT(*) FROM admin_reports WHERE status='draft'",'icon'=>'bi-file-earmark-text'],
            ['label'=>'This Month','sql'=>"SELECT COUNT(*) FROM admin_reports WHERE created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')",'icon'=>'bi-calendar2-check'],
        ],
        'list_sql'=>"SELECT id,report_code,title,report_type,period_start,period_end,status,updated_at
                    FROM admin_reports ORDER BY updated_at DESC",
        'columns'=>[
            ['key'=>'report_code','label'=>'Code'],
            ['key'=>'title','label'=>'Report'],
            ['key'=>'report_type','label'=>'Type'],
            ['key'=>'period_start','label'=>'From'],
            ['key'=>'period_end','label'=>'To'],
            ['key'=>'status','label'=>'Status','html'=>true,'format'=>fn($v)=>adminCrudStatusBadge((string)$v)],
            ['key'=>'updated_at','label'=>'Updated'],
        ],
        'order'=>[[6,'desc']],
    ];

    $configs['settings'] = [
        'key'=>'settings','title'=>'Settings','singular'=>'Setting','table'=>'portal_settings','pk'=>'id','page'=>'/admin/settings.php',
        'intro'=>'Manage portal configuration values that do not belong in server secrets.',
        'fields'=>[
            'setting_key'=>['label'=>'Setting Key','type'=>'text','required'=>true,'placeholder'=>'example: programme.support_phone','col'=>'col-md-4'],
            'category'=>['label'=>'Category','type'=>'text','required'=>true,'default'=>'general','col'=>'col-md-3'],
            'is_public'=>['label'=>'Public Setting','type'=>'checkbox','default'=>0,'col'=>'col-md-2'],
            'setting_value'=>['label'=>'Value','type'=>'textarea','rows'=>3,'col'=>'col-12'],
            'description'=>['label'=>'Description','type'=>'textarea','rows'=>2,'col'=>'col-12'],
        ],
        'stats'=>[
            ['label'=>'Settings','sql'=>'SELECT COUNT(*) FROM portal_settings','icon'=>'bi-gear'],
            ['label'=>'Public Settings','sql'=>'SELECT COUNT(*) FROM portal_settings WHERE is_public=1','icon'=>'bi-globe2'],
            ['label'=>'Categories','sql'=>'SELECT COUNT(DISTINCT category) FROM portal_settings','icon'=>'bi-tags'],
            ['label'=>'Updated This Month','sql'=>"SELECT COUNT(*) FROM portal_settings WHERE updated_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')",'icon'=>'bi-clock-history'],
        ],
        'list_sql'=>"SELECT id,setting_key,category,setting_value,is_public,updated_at
                    FROM portal_settings ORDER BY category,setting_key",
        'columns'=>[
            ['key'=>'setting_key','label'=>'Key'],
            ['key'=>'category','label'=>'Category'],
            ['key'=>'setting_value','label'=>'Value','format'=>fn($v)=>mb_strlen((string)$v)>80 ? mb_substr((string)$v,0,80).'…' : (string)$v],
            ['key'=>'is_public','label'=>'Public','html'=>true,'format'=>fn($v)=>$v ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>'],
            ['key'=>'updated_at','label'=>'Updated'],
        ],
    ];

    return $configs[$key] ?? throw new InvalidArgumentException('Unknown admin CRUD page: ' . $key);
}
