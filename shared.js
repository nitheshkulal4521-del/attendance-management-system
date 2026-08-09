console.log("NEW SHARED JS 2026");
function getInitials(name) {
    return name
        .trim()
        .split(/\s+/)
        .map(word => word.charAt(0))
        .slice(0, 2)
        .join('')
        .toUpperCase();
}





function renderSidebar(active){
 const items = [
    {href:'teacher_dashboard.php', icon:'grid', label:'Dashboard', key:'dashboard'},
    {href:'semester_selection.php', icon:'users', label:'Add Student', key:'add-student'},
    {href:'class_management.php', icon:'book-open', label:'Manage Classes', key:'classes'},
    {href:'mark_attendance.php', icon:'check-square', label:'Mark Attendance', key:'attendance'},
    {href:'attendance_report.php', icon:'bar-chart-2', label:'Attendance Report', key:'report'},

    // NO badge:'3'
    {href:'shortage_list.php', icon:'alert-triangle', label:'Shortage List', key:'shortage'},

    {href:'grace_management.php', icon:'shield', label:'Grace Management', key:'grace'},
    {href:'view_reasons.php', icon:'file-text', label:'Reasons & Certificates', key:'reasons'}
];
  const icons = {
    'grid':'<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
    'users':'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'book-open':'<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    'check-square':'<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'bar-chart-2':'<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'alert-triangle':'<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    'shield':'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'file-text':'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
  };
  let html = '<div class="sidebar"><div class="sidebar-section"><div class="sidebar-label">Navigation</div>';
  items.forEach(it=>{
    const isActive = it.key===active;
    const badgeHtml = it.badge ? `<span class="nav-badge">${it.badge}</span>`:'';
    html += `<a href="${it.href}" class="nav-item${isActive?' active':''}">
      <svg viewBox="0 0 24 24">${icons[it.icon]}</svg>${it.label}${badgeHtml}
    </a>`;
  });
  html += '</div></div>';
  return html;
}

document.addEventListener("DOMContentLoaded",()=>{

    const sb=document.getElementById("sidebar-mount");

    if(sb){

        sb.innerHTML=renderSidebar(
            sb.dataset.active||""
        );

    }

});

function toggleSidebar(){

    document
        .querySelector(".sidebar")
        .classList
        .toggle("show");

}

