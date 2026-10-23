/* ============================================================
   js/features.js — front-end feature switches.

   Flip a flag to false and the matching UI disappears: menu
   entries, buttons and whole pages.

   IMPORTANT — these toggles control VISIBILITY ONLY.
   They are not a security boundary. Every endpoint in api/
   independently re-checks the session, the role capabilities
   and (for teachers) the assigned grades. Turning a flag back
   on in devtools reveals a button, never data.
   ============================================================ */
window.FEATURES = {
  /* Students */
  studentImport   : true,   // Excel/CSV upload → validate → preview → confirm
  studentExport   : true,   // export buttons (formats below refine this)
  excelExport     : true,   // .xlsx output
  pdfExport       : true,   // printable report / PDF output
  studentIdCard   : true,   // ID card preview + print

  /* Attendance */
  attendance      : true,   // register, bulk marking, copy last week
  attendanceAlerts: true,   // red absence alerts + bell notifications

  /* Academic */
  studentPromotion: true,   // year-end promotion workflow

  /* Administration */
  backupRestore   : true,   // backup download / restore (Master only)
  auditTrail      : true,   // audit log page (Admin and Master)

  /* Platform */
  pwa             : true    // service worker, install prompt, offline queue
};

/* Small helper used across the UI: FEATURE('pdfExport') */
window.FEATURE = function (name) {
  return window.FEATURES ? window.FEATURES[name] !== false : true;
};
