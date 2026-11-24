/**
 * ALPHA GARAGE — Client-Side Bilingual (English / Amharic) Localization Engine
 * Provides instant language switching, cookie synchronization, and dynamic string translation.
 */
(function () {
  'use strict';

  var currentLang = document.documentElement.lang || 'en';

  // Read cookie if present
  var match = document.cookie.match(/(?:^|;\s*)GMS_LANG=([^;]+)/);
  if (match && match[1]) {
    currentLang = decodeURIComponent(match[1]);
  }

  // Comprehensive Client-Side Translation Dictionary
  var dictionary = {
    // Branding & Core
    'Alpha Garage': 'አልፋ ጋራዥ',
    'Garage Management': 'የጋራዥ አስተዳደር',
    'Garage Management System': 'የጋራዥ አስተዳደር ሥርዓት',
    'Luxury Automotive Care': 'የቅንጦት ተሽከርካሪዎች እንክብካቤ',
    'Back to Home': 'ወደ መነሻ',
    'Back to Homepage': 'ወደ መነሻ ገጽ ተመለስ',
    'Back to Dashboard': 'ወደ ዳሽቦርድ ተመለስ',
    'Back to Sign In': 'ወደ መግቢያ ተመለስ',
    'Sign In': 'ግባ',
    'Sign Out': 'ውጣ',
    'Sign in': 'ግባ',
    'Sign out': 'ውጣ',
    'Client sign in': 'የደንበኛ መግቢያ',
    'Get Started': 'አሁኑኑ ጀምር',
    'Register': 'ተመዝገብ',
    'Create Account': 'መለያ ፍጠር',
    'Dashboard': 'ዳሽቦርድ',
    'My Dashboard': 'የእኔ ዳሽቦርድ',
    'My Account': 'የእኔ መለያ',
    'Theme': 'ገጽታ',
    'Switch theme': 'ገጽታ ቀይር',
    'Billing': 'የክፍያ መጠየቂያዎች',
    'Book Service Visit': 'የአገልግሎት ቀጠሮ ያስይዙ',
    'Select your vehicle and service package for priority workshop booking.': 'ለቅድሚያ የዎርክሾፕ ቀጠሮ ተሽከርካሪዎን እና የአገልግሎት ጥቅልዎን ይምረጡ።',
    'Schedule your vehicle maintenance or repair visit in minutes.': 'የተሽከርካሪዎን ጥገና በጥቂት ደቂቃዎች ውስጥ ቀጠሮ ይያዙ።',
    '+ Add New Vehicle': '+ አዲስ ተሽከርካሪ ያክሉ',
    'Use Existing Vehicle': 'የተመዘገበ ተሽከርካሪ ይጠቀሙ',
    'Confirm Booking': 'ቀጠሮ ያረጋግጡ',

    // Video Management & Gallery
    'Videos': 'ቪዲዮዎች',
    'Video Gallery': 'የቪዲዮ ማሳያ',
    'Video Management': 'የቪዲዮ አስተዳደር',
    'Watch Video': 'ቪዲዮ ይመልከቱ',
    'Explore our workshop in action': 'የዎርክሾፕ ሥራዎቻችንን በተግባር ይመልከቱ',
    'All Videos': 'ሁሉም ቪዲዮዎች',
    'Play Video': 'ቪዲዮውን ያጫውቱ',
    'YouTube Video': 'የዩቲዩብ ቪዲዮ',
    'Uploaded Video': 'የተጫነ ቪዲዮ',
    'Add Video': 'ቪዲዮ ጨምር',
    '+ Add Video': '+ አዲስ ቪዲዮ ጨምር',
    'Edit Video': 'ቪዲዮ አርትዕ',
    'Delete Video': 'ቪዲዮ ሰርዝ',
    'Are you sure you want to delete this video?': 'እርግጠኛ ነዎት ይህን ቪዲዮ መሰረዝ ይፈልጋሉ?',
    'Preview Video': 'ቪዲዮውን ቅድመ ዕይታ',
    'Close Preview': 'ቅድመ እይታ ዝጋ',
    'No videos found.': 'ምንም ቪዲዮ አልተገኘም።',
    'Search videos…': 'ቪዲዮዎችን ፈልግ…',
    'Filter by category': 'በምድብ አጣራ',
    'Save Video': 'ቪዲዮውን አስቀምጥ',

    // Buttons & Actions
    'Save': 'አስቀምጥ',
    'Cancel': 'ሰርዝ',
    'Confirm': 'አረጋግጥ',
    'Please confirm': 'እባክዎ ያረጋግጡ',
    'Delete': 'አጥፋ',
    'Edit': 'አስተካክል',
    'View': 'እይ',
    'Search': 'ፈልግ',
    'Filter': 'አጣራ',
    'Filter this page…': 'በዚህ ገጽ ላይ ፈልግ…',
    'Close': 'ዝጋ',
    'Submit': 'አስገባ',
    'Print': 'አትም',
    'Download': 'አውርድ',
    'Export': 'ላክ',
    'Dismiss': 'አሰናብት',
    '‹ Prev': '‹ ቀዳሚ',
    'Next ›': 'ቀጣይ ›',

    // Statuses
    'Pending': 'በመጠባበቅ ላይ',
    'Diagnosing': 'ምርመራ ላይ',
    'Waiting for Parts': 'ዕቃ በመጠባበቅ ላይ',
    'In Progress': 'በመስራት ላይ',
    'Completed': 'የተጠናቀቀ',
    'Delivered': 'የተረከበ',
    'Cancelled': 'የተሰረዘ',
    'Paid': 'የተከፈለ',
    'Unpaid': 'ያልተከፈለ',
    'Partially Paid': 'በከፊል የተከፈለ',
    'Overdue': 'ጊዜው ያለፈበት',
    'Draft': 'ረቂቅ',
    'Healthy Stock': 'በቂ ክምችት',
    'Low Stock': 'ዝቅተኛ ክምችት',
    'Out of Stock': 'ያለቀ ዕቃ',

    // Priorities
    'Low': 'ዝቅተኛ',
    'Medium': 'መካከለኛ',
    'High': 'ከፍተኛ',
    'Urgent': 'አስቸኳይ',

    // Alerts & Notifications
    'Alerts': 'ማሳወቂያዎች',
    'Mark all read': 'ሁሉንም እንደተነበቡ ምልክት አድርግ',
    'No unread notifications': 'ያልተነበቡ ማሳወቂያዎች የሉም',
    'View all alerts': 'ሁሉንም ማሳወቂያዎች እይ',
    'Stock alerts': 'የክምችት ማሳወቂያዎች',

    // Chart Legends & Datasets
    'Revenue': 'ገቢ',
    'Expenses': 'ወጪዎች',
    'Net profit': 'የተጣራ ትርፍ',
    'Brakes': 'ፍሬን',
    'Filters': 'ማጣሪያዎች',
    'Tires': 'ጎማዎች',
    'Suspension': 'እገዳ',
    'Engine': 'ሞተር',
    'Electrical': 'ኤሌክትሪክ',
    'Fluids': 'ዘይቶችና ፈሳሾች',
    'Purchase': 'ግዢ',
    'Issue': 'ወጪ የተደረገ',
    'Adjustment': 'ማስተካከያ',
    'Cash': 'ጥሬ ገንዘብ',
    'Bank Transfer': 'የባንክ ማስተላለፍ',
    'Credit Card': 'ክሬዲት ካርድ',
    'Cheque': 'ቼክ',
    'Mobile Money': 'ሞባይል ክፍያ',
    'Telebirr': 'ቴሌብር',
    'CBE Birr': 'ሲቢኢ ብር',
    '0–30 Days': '0–30 ቀናት',
    '31–60 Days': '31–60 ቀናት',
    '61–90 Days': '61–90 ቀናት',
    '90+ Days': 'ከ90 ቀናት በላይ',

    // Customer Portal Specific
    'Customer Portal': 'የደንበኞች ፖርታል',
    'VIP Client Services': 'ቪአይፒ የደንበኞች አገልግሎት',
    'My Garage': 'የእኔ ጋራዥ',
    'Repair Orders': 'የጥገና ትዕዛዞች',
    'Link Past Records': 'የቀደሙ መዝገቦችን አገናኝ',
    'Service Visits': 'የአገልግሎት ጉብኝቶች',
    'No active repair orders': 'ምንም ንቁ የጥገና ትዕዛዞች የሉም',
    'No history': 'ምንም ታሪክ የለም',
    'Registered Vehicles': 'የተመዘገቡ ተሽከርካሪዎች',
    'Upcoming Appointments': 'መጪ ቀጠሮዎች',
    'Active Repairs': 'በሂደት ላይ ያሉ ጥገናዎች',
    'Outstanding Balance': 'ያልተከፈለ ቀሪ ሂሳብ',
    'ACCOUNT OVERVIEW': 'የመለያ አጠቃላይ እይታ',
    'Live fleet & service status': 'የቀጥታ ተሽከርካሪዎች እና የአገልግሎት ሁኔታ',
    'Fleet overview & workshop status': 'የተሽከርካሪዎች አጠቃላይ እይታ እና የዎርክሾፕ ሁኔታ',
    'Real-time overview of your registered vehicles and workshop activity': 'የተመዘገቡ ተሽከርካሪዎችዎ እና የዎርክሾፕ እንቅስቃሴ የቀጥታ እይታ',
    'WORKSTATION METRICS': 'የሥራ ጣቢያ መለኪያዎች',
    'SERVICE ANALYTICS': 'የአገልግሎት ትንታኔ',
    'Your vehicle service history': 'የተሽከርካሪዎ የአገልግሎት ታሪክ',
    'Repair Order Status': 'የጥገና ትዕዛዝ ሁኔታ',
    '6-Month Service Activity': 'የ6 ወራት የአገልግሎት እንቅስቃሴ',
    'My Vehicles': 'የእኔ ተሽከርካሪዎች',
    'Service Appointments': 'የአገልግሎት ቀጠሮዎች',
    'Workshop Repair Status': 'የዎርክሾፕ ጥገና ሁኔታ',
    'Billing & Invoices': 'የክፍያ መጠየቂያዎች እና ደረሰኞች',
    'Check & Link Records': 'መዝገቦችን ፈትሽ እና አገናኝ',
    'Schedule First Service': 'የመጀመሪያውን አገልግሎት ቀጠሮ ይያዙ',
    'Plate No.': 'የሰሌዳ ቁጥር',
    'Make & Model': 'ዓይነት እና ሞዴል',
    'Year': 'ዓመት',
    'Fuel': 'የነዳጅ ዓይነት',
    'Action': 'እርምጃ',
    'Petrol': 'ቤንዚን',
    'Diesel': 'ናፍጣ',
    'Hybrid': 'ሃይብሪድ',
    'Electric': 'ኤሌክትሪክ',
    'Book visit': 'ቀጠሮ ይያዙ',
    'Book Premium Service Visit': 'ፕሪሚየም የአገልግሎት ጉብኝት ያስይዙ',
    'Schedule certified technician inspection & maintenance': 'የተመሰከረላቸው የቴክኒሺያን ምርመራ እና ጥገና ያቅዱ',
    'My Registered Vehicle': 'የእኔ የተመዘገበ ተሽከርካሪ',
    '+ Add New Vehicle': '+ አዲስ ተሽከርካሪ ጨምር',
    'Select Your Vehicle': 'ተሽከርካሪዎን ይምረጡ',
    'License Plate': 'የሰሌዳ ቁጥር',
    'Make': 'የመኪናው ስም (Make)',
    'Model': 'ሞዴል (Model)',
    'Year': 'የተመረተበት ዓመት',
    'Requested Service Package': 'የተጠየቀው የአገልግሎት ጥቅል',
    '-- General Inspection & Diagnosis --': '-- አጠቃላይ ምርመራ እና ዲያግኖስቲክስ --',
    'Preferred Date': 'የሚመረጥበት ቀን',
    'Preferred Time': 'የሚመረጥበት ሰዓት',
    'Notes / Specific Concerns': 'ማስታወሻዎች / ልዩ ስጋቶች',
    'Describe any symptoms, dashboard warning lights, or requested checks...': 'ምልክቶችን፣ የዳሽቦርድ ማስጠንቀቂያ መብራቶችን ወይም የሚፈለጉ ምርመራዎችን ይግለጹ...',
    'Confirm & Book Service': 'አረጋግጥ እና አገልግሎት ያዝ',
    'Or open full booking page': 'ወይም ሙሉውን የማስያዣ ገጽ ይክፈቱ',
    'Booking #': 'የቀጠሮ ቁጥር',
    'Date & Time': 'ቀን እና ሰዓት',
    'General Service': 'አጠቃላይ አገልግሎት',
    'Job Card': 'የሥራ ካርድ',
    'Reported Issue': 'የተገለጸው ችግር',
    'Progress Stage': 'የሂደት ደረጃ',
    'Invoice No.': 'የደረሰኝ ቁጥር',
    'Issue Date': 'የተሰጠበት ቀን',
    'Total': 'ጠቅላላ',
    'Balance Due': 'ቀሪ ዕዳ',

    // Months (for charts)
    'Jan': 'ጥር',
    'Feb': 'የካቲት',
    'Mar': 'መጋቢት',
    'Apr': 'ሚያዝያ',
    'May': 'ግንቦት',
    'Jun': 'ሰኔ',
    'Jul': 'ሐምሌ',
    'Aug': 'ነሐሴ',
    'Sep': 'መስከረም',
    'Oct': 'ጥቅምት',
    'Nov': 'ህዳር',
    'Dec': 'ታህሳስ',

    // Form Modals & Prompts
    'Are you sure?': 'እርግጠኛ ነዎት?',
    'This action cannot be undone.': 'ይህ እርምጃ ሊቀለበስ አይችልም።',
    'Please enter a valid value.': 'እባክዎ ትክክለኛ እሴት ያስገቡ።',
    'Password must be at least 10 characters and include uppercase, lowercase, numbers, and symbols.':
      'የይለፍ ቃል ቢያንስ 10 ፊደላት መሆን አለበት እንዲሁም አቢይ፣ ንዑስ ፊደላት፣ ቁጥሮች እና ምልክቶችን ማካተት አለበት።'
  };

  function translate(key, fallback, params) {
    if (typeof fallback === 'object' && params === undefined) {
      params = fallback;
      fallback = undefined;
    }

    var text = key;
    if (currentLang === 'am') {
      text = dictionary[key] || (fallback !== undefined ? fallback : key);
    } else {
      text = fallback !== undefined ? fallback : key;
    }

    if (params && typeof params === 'object') {
      Object.keys(params).forEach(function (paramKey) {
        var val = params[paramKey];
        text = text.replace(new RegExp(':' + paramKey, 'g'), val)
                   .replace(new RegExp('\\{' + paramKey + '\\}', 'g'), val);
      });
    }

    return text;
  }

  function syncSwitcherUI(lang) {
    var activeLang = lang || document.documentElement.lang || currentLang || 'en';
    var pills = document.querySelectorAll('.lang-switcher-pill');
    pills.forEach(function (pill) {
      var buttons = pill.querySelectorAll('.lang-switch-btn');
      buttons.forEach(function (b) {
        var isTarget = b.getAttribute('data-lang') === activeLang;
        if (isTarget) {
          b.classList.add('active');
          b.setAttribute('aria-pressed', 'true');
        } else {
          b.classList.remove('active');
          b.setAttribute('aria-pressed', 'false');
        }
      });
    });
  }

  function setLanguage(lang) {
    if (lang !== 'en' && lang !== 'am') return;
    document.cookie = 'GMS_LANG=' + encodeURIComponent(lang) + ';path=/;max-age=31536000;SameSite=Lax';
    currentLang = lang;
    document.documentElement.lang = lang;
    syncSwitcherUI(lang);
    if (typeof window.renderDashboardCharts === 'function') {
      try { window.renderDashboardCharts(); } catch (e) { /* ignore */ }
    }
  }

  function initSwitcher() {
    // Initial UI synchronization on page load
    syncSwitcherUI();

    var buttons = document.querySelectorAll('.lang-switch-btn');
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        var targetLang = btn.getAttribute('data-lang');
        if (targetLang) {
          setLanguage(targetLang);
          syncSwitcherUI(targetLang);
        }
      });
    });

    // Translate dynamic elements marked with data-i18n
    if (currentLang === 'am') {
      var translatableElements = document.querySelectorAll('[data-i18n]');
      translatableElements.forEach(function (el) {
        var key = el.getAttribute('data-i18n');
        if (key && dictionary[key]) {
          el.textContent = dictionary[key];
        }
      });

      var translatableInputs = document.querySelectorAll('[data-i18n-placeholder]');
      translatableInputs.forEach(function (input) {
        var key = input.getAttribute('data-i18n-placeholder');
        if (key && dictionary[key]) {
          input.setAttribute('placeholder', dictionary[key]);
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSwitcher);
  } else {
    initSwitcher();
  }

  // Public API
  var api = {
    lang: currentLang,
    language: currentLang,
    t: translate,
    setLang: setLanguage,
    dictionary: dictionary,
    translations: dictionary
  };

  window.GMS_I18N = api;
  window.GMS_LANG = api;
})();
