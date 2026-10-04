<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Master data seed lists (PRD §7.1, A11) — PROPOSED
|--------------------------------------------------------------------------
| The v4 appendix these came from is not available; these lists are the build's proposal
| (docs/decisions.md, 2026-09-28) and are editable in A11 (P1.8). Codes are immutable once
| used; labels may change. Order = display order (sort_order). No personal data.
*/

return [

    'religions' => [
        'HINDU' => 'Hindu',
        'CHRISTIAN' => 'Christian',
        'MUSLIM' => 'Muslim',
        'JAIN' => 'Jain',
        'SIKH' => 'Sikh',
        'BUDDHIST' => 'Buddhist',
        'PARSI' => 'Parsi',
        'JEWISH' => 'Jewish',
        'INTER_RELIGION' => 'Inter-religion',
        'NO_RELIGION' => 'No religion',
        'OTHER' => 'Other',
    ],

    // religion code => [caste code => label]. Christian "castes" are denominations, as on
    // Kerala matrimony sites. Every religion keeps an OTHER so no one is forced into a wrong box.
    'castes' => [
        'HINDU' => [
            'NAIR' => 'Nair',
            'EZHAVA' => 'Ezhava',
            'THIYYA' => 'Thiyya',
            'NAMBOOTHIRI' => 'Namboothiri',
            'BRAHMIN' => 'Brahmin (other)',
            'IYER' => 'Iyer',
            'IYENGAR' => 'Iyengar',
            'KSHATRIYA' => 'Kshatriya',
            'AMBALAVASI' => 'Ambalavasi',
            'VARIER' => 'Varier',
            'NAMBIAR' => 'Nambiar',
            'MENON' => 'Menon',
            'PILLAI' => 'Pillai',
            'KURUP' => 'Kurup',
            'VISWAKARMA' => 'Viswakarma',
            'DHEEVARA' => 'Dheevara',
            'CHETTIAR' => 'Chettiar',
            'VELLALA' => 'Vellala',
            'NADAR' => 'Nadar',
            'PULAYA' => 'Pulaya',
            'CHERUMAR' => 'Cherumar',
            'KUDUMBI' => 'Kudumbi',
            'VILAKKITHALA_NAIR' => 'Vilakkithala Nair',
            'SC' => 'Scheduled Caste',
            'ST' => 'Scheduled Tribe',
            'OTHER' => 'Other',
        ],
        'CHRISTIAN' => [
            'SYRO_MALABAR' => 'Roman Catholic — Syro-Malabar',
            'LATIN_CATHOLIC' => 'Roman Catholic — Latin',
            'SYRO_MALANKARA' => 'Roman Catholic — Syro-Malankara',
            'KNANAYA_CATHOLIC' => 'Knanaya Catholic',
            'KNANAYA_JACOBITE' => 'Knanaya Jacobite',
            'MALANKARA_ORTHODOX' => 'Malankara Orthodox',
            'JACOBITE' => 'Jacobite',
            'MARTHOMA' => 'Marthoma',
            'CSI' => 'CSI',
            'PENTECOSTAL' => 'Pentecostal',
            'BRETHREN' => 'Brethren',
            'EVANGELICAL' => 'Evangelical',
            'CHALDEAN' => 'Chaldean Syrian',
            'OTHER' => 'Other',
        ],
        'MUSLIM' => [
            'SUNNI' => 'Sunni',
            'SHIA' => 'Shia',
            'MAPPILA' => 'Mappila',
            'RAWTHER' => 'Rawther',
            'LABBAI' => 'Labbai',
            'PATHAN' => 'Pathan',
            'SAYYID' => 'Sayyid',
            'OTHER' => 'Other',
        ],
        'JAIN' => ['OTHER' => 'Other'],
        'SIKH' => ['OTHER' => 'Other'],
        'BUDDHIST' => ['OTHER' => 'Other'],
        'PARSI' => ['OTHER' => 'Other'],
        'JEWISH' => ['OTHER' => 'Other'],
        'INTER_RELIGION' => ['OTHER' => 'Other'],
        'NO_RELIGION' => ['OTHER' => 'Other'],
        'OTHER' => ['OTHER' => 'Other'],
    ],

    // The 27 nakshatras, Malayalam names in the traditional order.
    'stars' => [
        'ASHWATHI' => 'Ashwathi', 'BHARANI' => 'Bharani', 'KARTHIKA' => 'Karthika',
        'ROHINI' => 'Rohini', 'MAKAYIRAM' => 'Makayiram', 'THIRUVATHIRA' => 'Thiruvathira',
        'PUNARTHAM' => 'Punartham', 'POOYAM' => 'Pooyam', 'AYILYAM' => 'Ayilyam',
        'MAKAM' => 'Makam', 'POORAM' => 'Pooram', 'UTHRAM' => 'Uthram',
        'ATHAM' => 'Atham', 'CHITHIRA' => 'Chithira', 'CHOTHI' => 'Chothi',
        'VISHAKHAM' => 'Vishakham', 'ANIZHAM' => 'Anizham', 'THRIKKETTA' => 'Thrikketta',
        'MOOLAM' => 'Moolam', 'POORADAM' => 'Pooradam', 'UTHRADAM' => 'Uthradam',
        'THIRUVONAM' => 'Thiruvonam', 'AVITTAM' => 'Avittam', 'CHATHAYAM' => 'Chathayam',
        'POORURUTTATHI' => 'Pooruruttathi', 'UTHRATTATHI' => 'Uthrattathi', 'REVATHI' => 'Revathi',
    ],

    // The 12 rasis (Malayalam names), Medam first.
    'rasis' => [
        'MEDAM' => 'Medam', 'EDAVAM' => 'Edavam', 'MITHUNAM' => 'Mithunam',
        'KARKIDAKAM' => 'Karkidakam', 'CHINGAM' => 'Chingam', 'KANNI' => 'Kanni',
        'THULAM' => 'Thulam', 'VRISCHIKAM' => 'Vrischikam', 'DHANU' => 'Dhanu',
        'MAKARAM' => 'Makaram', 'KUMBHAM' => 'Kumbham', 'MEENAM' => 'Meenam',
    ],

    // ISO 3166-1 alpha-2 codes. India first, then where Malayalis most often live.
    'countries' => [
        'IN' => 'India', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
        'KW' => 'Kuwait', 'OM' => 'Oman', 'BH' => 'Bahrain', 'US' => 'United States',
        'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand',
        'IE' => 'Ireland', 'DE' => 'Germany', 'SG' => 'Singapore', 'MY' => 'Malaysia',
        'MV' => 'Maldives', 'IL' => 'Israel', 'IT' => 'Italy', 'OTHER' => 'Other',
    ],

    // Indian states and union territories (ISO 3166-2:IN), Kerala and its neighbours first.
    'states' => [
        'IN' => [
            'KL' => 'Kerala', 'TN' => 'Tamil Nadu', 'KA' => 'Karnataka', 'PY' => 'Puducherry',
            'LD' => 'Lakshadweep', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam',
            'BR' => 'Bihar', 'CT' => 'Chhattisgarh', 'GA' => 'Goa', 'GJ' => 'Gujarat',
            'HR' => 'Haryana', 'HP' => 'Himachal Pradesh', 'JH' => 'Jharkhand', 'MP' => 'Madhya Pradesh',
            'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram',
            'NL' => 'Nagaland', 'OR' => 'Odisha', 'PB' => 'Punjab', 'RJ' => 'Rajasthan',
            'SK' => 'Sikkim', 'TG' => 'Telangana', 'TR' => 'Tripura', 'UP' => 'Uttar Pradesh',
            'UT' => 'Uttarakhand', 'WB' => 'West Bengal', 'AN' => 'Andaman and Nicobar Islands',
            'CH' => 'Chandigarh', 'DH' => 'Dadra and Nagar Haveli and Daman and Diu',
            'DL' => 'Delhi', 'JK' => 'Jammu and Kashmir', 'LA' => 'Ladakh',
        ],
    ],

    // Kerala's 14 districts, north to south. Other states' districts are added in A11 as needed.
    'districts' => [
        'KL' => [
            'KASARAGOD' => 'Kasaragod', 'KANNUR' => 'Kannur', 'WAYANAD' => 'Wayanad',
            'KOZHIKODE' => 'Kozhikode', 'MALAPPURAM' => 'Malappuram', 'PALAKKAD' => 'Palakkad',
            'THRISSUR' => 'Thrissur', 'ERNAKULAM' => 'Ernakulam', 'IDUKKI' => 'Idukki',
            'KOTTAYAM' => 'Kottayam', 'ALAPPUZHA' => 'Alappuzha', 'PATHANAMTHITTA' => 'Pathanamthitta',
            'KOLLAM' => 'Kollam', 'THIRUVANANTHAPURAM' => 'Thiruvananthapuram',
        ],
    ],

    'education' => [
        'DOCTORATE' => 'Doctorate (PhD)', 'MBBS' => 'MBBS', 'MD_MS' => 'MD / MS (Medicine)',
        'BDS_MDS' => 'BDS / MDS', 'BAMS_BHMS' => 'BAMS / BHMS', 'NURSING' => 'Nursing (BSc / MSc / GNM)',
        'PHARMACY' => 'Pharmacy (B.Pharm / M.Pharm / Pharm.D)', 'BTECH' => 'B.Tech / BE',
        'MTECH' => 'M.Tech / ME', 'MBA' => 'MBA / PGDM', 'MCA' => 'MCA', 'BCA' => 'BCA',
        'MSC' => 'M.Sc', 'BSC' => 'B.Sc', 'MA' => 'MA', 'BA' => 'BA', 'MCOM' => 'M.Com',
        'BCOM' => 'B.Com', 'BBA' => 'BBA', 'CA_CS_CMA' => 'CA / CS / CMA', 'LAW' => 'LLB / LLM',
        'BED_MED' => 'B.Ed / M.Ed', 'ARCHITECTURE' => 'Architecture', 'DIPLOMA' => 'Diploma / Polytechnic',
        'ITI' => 'ITI', 'HIGHER_SECONDARY' => 'Higher Secondary (Plus Two)', 'SSLC' => 'SSLC / 10th',
        'OTHER' => 'Other',
    ],

    'occupations' => [
        'SOFTWARE' => 'Software Professional', 'ENGINEER' => 'Engineer (non-IT)', 'DOCTOR' => 'Doctor',
        'NURSE' => 'Nurse', 'PHARMACIST' => 'Pharmacist', 'ALLIED_HEALTH' => 'Allied Health Professional',
        'TEACHER' => 'Teacher / Lecturer', 'PROFESSOR' => 'Professor / Researcher', 'BANKING' => 'Banking / Finance',
        'ACCOUNTANT' => 'Accountant / Auditor', 'CHARTERED_ACCOUNTANT' => 'Chartered Accountant',
        'LAWYER' => 'Lawyer / Legal', 'GOVT_SERVICE' => 'Government Service', 'DEFENCE' => 'Defence / Police',
        'BUSINESS' => 'Business Owner', 'SALES_MARKETING' => 'Sales / Marketing', 'MANAGER' => 'Manager / Executive',
        'HR_ADMIN' => 'HR / Administration', 'ARCHITECT' => 'Architect / Designer', 'MEDIA' => 'Media / Journalism',
        'HOSPITALITY' => 'Hospitality / Tourism', 'AVIATION' => 'Aviation / Merchant Navy',
        'CUSTOMER_SUPPORT' => 'Customer Support', 'TECHNICIAN' => 'Technician / Skilled Worker',
        'AGRICULTURE' => 'Agriculture / Farming', 'SELF_EMPLOYED' => 'Self-employed / Freelancer',
        'STUDENT' => 'Student', 'HOMEMAKER' => 'Homemaker', 'NOT_WORKING' => 'Not working', 'OTHER' => 'Other',
    ],

    // Annual income in lakh rupees (1 lakh = 1,00,000). [label, min lakh, max lakh | null].
    'income_bands' => [
        'NONE' => ['No income', 0, 0],
        'UPTO_2L' => ['Below ₹2 lakh', 0, 2],
        '2_4L' => ['₹2 – 4 lakh', 2, 4],
        '4_6L' => ['₹4 – 6 lakh', 4, 6],
        '6_8L' => ['₹6 – 8 lakh', 6, 8],
        '8_10L' => ['₹8 – 10 lakh', 8, 10],
        '10_15L' => ['₹10 – 15 lakh', 10, 15],
        '15_20L' => ['₹15 – 20 lakh', 15, 20],
        '20_30L' => ['₹20 – 30 lakh', 20, 30],
        '30_50L' => ['₹30 – 50 lakh', 30, 50],
        '50L_1CR' => ['₹50 lakh – 1 crore', 50, 100],
        'ABOVE_1CR' => ['Above ₹1 crore', 100, null],
    ],

    'mother_tongues' => [
        'MALAYALAM' => 'Malayalam', 'TAMIL' => 'Tamil', 'KANNADA' => 'Kannada', 'TULU' => 'Tulu',
        'KONKANI' => 'Konkani', 'TELUGU' => 'Telugu', 'HINDI' => 'Hindi', 'URDU' => 'Urdu',
        'ENGLISH' => 'English', 'MARATHI' => 'Marathi', 'BENGALI' => 'Bengali', 'GUJARATI' => 'Gujarati',
        'PUNJABI' => 'Punjabi', 'OTHER' => 'Other',
    ],

    // Generic option lists (master_options.group). Editable in A11.
    'options' => [
        'diet' => ['VEG' => 'Vegetarian', 'NON_VEG' => 'Non-vegetarian', 'EGGETARIAN' => 'Eggetarian', 'VEGAN' => 'Vegan'],
        'smoking' => ['NO' => 'No', 'OCCASIONALLY' => 'Occasionally', 'YES' => 'Yes'],
        'drinking' => ['NO' => 'No', 'OCCASIONALLY' => 'Occasionally', 'YES' => 'Yes'],
        'complexion' => ['VERY_FAIR' => 'Very fair', 'FAIR' => 'Fair', 'WHEATISH' => 'Wheatish', 'WHEATISH_BROWN' => 'Wheatish brown', 'DARK' => 'Dark'],
        'body_type' => ['SLIM' => 'Slim', 'AVERAGE' => 'Average', 'ATHLETIC' => 'Athletic', 'HEAVY' => 'Heavy'],
        'family_type' => ['NUCLEAR' => 'Nuclear family', 'JOINT' => 'Joint family'],
        'family_status' => ['MIDDLE_CLASS' => 'Middle class', 'UPPER_MIDDLE' => 'Upper middle class', 'RICH' => 'Rich', 'AFFLUENT' => 'Affluent'],
        'family_values' => ['TRADITIONAL' => 'Traditional', 'MODERATE' => 'Moderate', 'LIBERAL' => 'Liberal'],

        // Word lists for the A04 automatic profanity pre-flag (whole words, any case). Short seeds:
        // moderators extend them in A11 (P1.8). Codes are opaque (the word is the label).
        'profanity_en' => [
            'W01' => 'fuck', 'W02' => 'shit', 'W03' => 'bitch', 'W04' => 'bastard', 'W05' => 'asshole',
            'W06' => 'slut', 'W07' => 'whore', 'W08' => 'dick', 'W09' => 'pussy', 'W10' => 'cunt',
        ],
        'profanity_ml' => [
            'W01' => 'പട്ടി', 'W02' => 'തെണ്ടി', 'W03' => 'പൂറി', 'W04' => 'മൈര്', 'W05' => 'കഴുവേറി', 'W06' => 'പന്നി',
        ],
        'profanity_manglish' => [
            'W01' => 'myre', 'W02' => 'myr', 'W03' => 'poori', 'W04' => 'thendi', 'W05' => 'kazhuveri', 'W06' => 'patti',
        ],
    ],

];
