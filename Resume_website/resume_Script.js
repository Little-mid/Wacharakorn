
(function () {
    "use strict";

    /* HERO */
    setTimeout(function () {
        var l = document.querySelector('.hero-l');
        var r = document.querySelector('.hero-r');
        if (l) l.classList.add('go');
        if (r) r.classList.add('go');
    }, 80);

    /* PROGRESS */
    var prog = document.getElementById('prog');
    window.addEventListener('scroll', function () {
        var h = document.documentElement.scrollHeight - innerHeight;
        if (prog && h > 0) prog.style.width = Math.min(scrollY / h * 100, 100) + '%';
    }, { passive: true });

    /* NAV SPY */
    var nlinks = document.getElementById('navlinks');
    var nind = document.getElementById('nind');
    var nls = document.querySelectorAll('.nl');
    var SIDS = ['about', 'skills', 'experience', 'work'];
    function moveInd(tab) {
        if (!tab || !nind || !nlinks) return;
        var cr = tab.getBoundingClientRect();
        var pr = nlinks.getBoundingClientRect();
        nind.style.left = (cr.left - pr.left) + 'px';
        nind.style.width = cr.width + 'px';
    }
    function spySect() {
        var y = scrollY + 80; var cur = SIDS[0];
        SIDS.forEach(function (id) { var el = document.getElementById(id); if (el && el.offsetTop <= y) cur = id; });
        nls.forEach(function (l) {
            var on = l.getAttribute('href') === '#' + cur;
            l.classList.toggle('on', on);
            if (on) moveInd(l);
        });
    }
    window.addEventListener('scroll', spySect, { passive: true });
    setTimeout(spySect, 200);
    nls.forEach(function (l) {
        l.addEventListener('mouseenter', function () { moveInd(l); });
        l.addEventListener('mouseleave', spySect);
        l.addEventListener('click', function (e) {
            e.preventDefault();
            var el = document.getElementById(l.getAttribute('href').slice(1));
            if (el) window.scrollTo({ top: el.getBoundingClientRect().top + scrollY - 66, behavior: 'smooth' });
        });
    });
    var hBtn = document.getElementById('heroBtn');
    if (hBtn) hBtn.addEventListener('click', function (e) {
        e.preventDefault();
        var el = document.getElementById('about');
        if (el) window.scrollTo({ top: el.getBoundingClientRect().top + scrollY - 66, behavior: 'smooth' });
    });

    /* FADE SCROLL */
    var fsObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e, i) {
            if (e.isIntersecting) { setTimeout(function () { e.target.classList.add('in'); }, i * 55); fsObs.unobserve(e.target); }
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.fs').forEach(function (el) { fsObs.observe(el); });

    /* SECTION UNDERLINE */
    var headObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('lit'); headObs.unobserve(e.target); } });
    }, { threshold: 0.5 });
    document.querySelectorAll('.shead').forEach(function (el) { headObs.observe(el); });

    /* TIMELINE */
    var tlObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); tlObs.unobserve(e.target); } });
    }, { threshold: 0.3 });
    document.querySelectorAll('.tli').forEach(function (el) { tlObs.observe(el); });

    /* COUNT UP */
    function countUp(el, to, dur) {
        var s = performance.now();
        (function tick(now) {
            var t = Math.min((now - s) / dur, 1);
            el.textContent = Math.floor((1 - Math.pow(1 - t, 3)) * to);
            if (t < 1) requestAnimationFrame(tick);
        })(s);
    }
    var cuObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (!e.isIntersecting) return;
            var n = e.target.querySelector('.snum2');
            if (!n || n.dataset.done) return;
            n.dataset.done = '1';
            var v = parseFloat(n.dataset.to);
            if (!isNaN(v)) countUp(n, v, 1100);
        });
    }, { threshold: 0.7 });
    document.querySelectorAll('.scard').forEach(function (el) { cuObs.observe(el); });

    /* PROFILE TILT */
    var pcard = document.getElementById('pcard');
    if (pcard) {
        pcard.addEventListener('mousemove', function (e) {
            var r = pcard.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width - .5;
            var y = (e.clientY - r.top) / r.height - .5;
            pcard.style.transform = 'perspective(800px) rotateY(' + (x * 5) + 'deg) rotateX(' + (-y * 5) + 'deg) translateY(-4px)';
            pcard.style.boxShadow = (-x * 10) + 'px ' + (-y * 8) + 'px 28px rgba(0,0,0,.1),0 4px 24px rgba(0,0,0,.07)';
        });
        pcard.addEventListener('mouseleave', function () {
            pcard.style.transition = 'transform .45s,box-shadow .45s';
            pcard.style.transform = ''; pcard.style.boxShadow = '';
            setTimeout(function () { pcard.style.transition = ''; }, 450);
        });
    }

    /* CARD TILT */
    function applyTilt(card) {
        card.addEventListener('mousemove', function (e) {
            var r = card.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width - .5;
            var y = (e.clientY - r.top) / r.height - .5;
            card.style.transform = 'perspective(700px) rotateY(' + (x * 9) + 'deg) rotateX(' + (-y * 7) + 'deg) scale(1.02)';
            card.style.boxShadow = (-x * 14) + 'px ' + (-y * 10) + 'px 32px rgba(0,0,0,.1)';
        });
        card.addEventListener('mouseleave', function () {
            card.style.transition = 'transform .4s,box-shadow .4s';
            card.style.transform = ''; card.style.boxShadow = '';
            setTimeout(function () { card.style.transition = ''; }, 400);
        });
    }
    document.querySelectorAll('.wcard').forEach(applyTilt);

    /* MAGNETIC */
    document.querySelectorAll('.btn-hero,.btn-cta').forEach(function (btn) {
        btn.addEventListener('mousemove', function (e) {
            var r = btn.getBoundingClientRect();
            btn.style.transform = 'translate(' + (e.clientX - r.left - r.width / 2) * .22 + 'px,' + (e.clientY - r.top - r.height / 2) * .22 + 'px)';
        });
        btn.addEventListener('mouseleave', function () {
            btn.style.transition = 'transform .4s,background .2s,box-shadow .2s';
            btn.style.transform = '';
            setTimeout(function () { btn.style.transition = ''; }, 400);
        });
    });

    /* PILL RIPPLE */
    document.querySelectorAll('.pill').forEach(function (p) {
        p.addEventListener('mousemove', function (e) {
            var r = p.getBoundingClientRect();
            p.style.setProperty('--rx', ((e.clientX - r.left) / r.width * 100) + '%');
            p.style.setProperty('--ry', ((e.clientY - r.top) / r.height * 100) + '%');
        });
    });

    /* SKILL STAGGER */
    var skObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (!e.isIntersecting) return;
            e.target.querySelectorAll('.pill').forEach(function (p, i) {
                p.style.opacity = '0'; p.style.transform = 'translateY(8px)';
                setTimeout(function () { p.style.transition = 'opacity .32s,transform .38s'; p.style.opacity = '1'; p.style.transform = 'translateY(0)'; }, i * 50);
            });
            skObs.unobserve(e.target);
        });
    }, { threshold: 0.3 });
    document.querySelectorAll('.skill-card').forEach(function (el) { skObs.observe(el); });

    /* CLICK RIPPLE */
    function addRipple(el) {
        el.addEventListener('click', function (e) {
            var r = el.getBoundingClientRect();
            var sz = Math.max(r.width, r.height) * 2;
            var sp = document.createElement('span');
            sp.style.cssText = 'position:absolute;border-radius:50%;pointer-events:none;width:' + sz + 'px;height:' + sz + 'px;left:' + (e.clientX - r.left - sz / 2) + 'px;top:' + (e.clientY - r.top - sz / 2) + 'px;background:rgba(255,255,255,.18);transform:scale(0);animation:ripple .5s ease-out forwards;';
            el.appendChild(sp);
            setTimeout(function () { sp.remove(); }, 550);
        });
    }
    document.querySelectorAll('.btn-hero,.btn-cta,.btn-ok,.fab').forEach(addRipple);

    /* PARALLAX */
    window.addEventListener('scroll', function () {
        var hl = document.querySelector('.hero-l');
        if (hl && hl.classList.contains('go')) hl.style.transform = 'translateY(' + (scrollY * .04) + 'px)';
    }, { passive: true });


    /* TOAST */
    function showToast(msg) {
        var t = document.getElementById('toast');
        if (!t) return;
        t.textContent = msg; t.classList.add('show');
        setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    /* MODAL */
    function openModal() { var m = document.getElementById('mbg'); if (m) m.classList.add('open'); }
    function closeModal() { var m = document.getElementById('mbg'); if (m) m.classList.remove('open'); }
    document.getElementById('mbg').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
    window.openModal = openModal; window.closeModal = closeModal;

    /* ADD PROJECT */
    var PALS = [
        { bg: 'linear-gradient(135deg,#eef2f8,#d4e4f4)', color: '#2a4c8a' },
        { bg: 'linear-gradient(135deg,#f4eef8,#e0d4f4)', color: '#5a2a8a' },
        { bg: 'linear-gradient(135deg,#f8f0ee,#f0e0d4)', color: '#8a3a2a' },
        { bg: 'linear-gradient(135deg,#eef8f2,#d0f0e4)', color: '#1C5E3C' }
    ];
    var palIdx = 0;
    function addProj() {
        var tEl = document.getElementById('pT');
        if (!tEl || !tEl.value.trim()) { if (tEl) tEl.focus(); return; }
        var t = tEl.value.trim();
        var d = document.getElementById('pD').value.trim();
        var s = document.getElementById('pS').value.trim().split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        var u = document.getElementById('pU').value.trim();
        var g = document.getElementById('pG').value.trim();
        var ab = t.split(' ').map(function (w) { return w[0]; }).join('').toUpperCase().slice(0, 4) || 'NEW';
        var pal = PALS[palIdx++ % PALS.length];
        var card = document.createElement('div');
        card.className = 'wcard'; card.style.position = 'relative'; card.style.overflow = 'hidden';
        card.innerHTML = '<div class="wthumb" style="background:' + pal.bg + ';color:' + pal.color + ';">' + ab + '<span class="wbadge">Project</span></div>'
            + '<div class="wbody"><div class="wname">' + t + '</div>'
            + '<div class="wdesc">' + (d || 'Personal project') + '</div>'
            + '<div class="wtags">' + s.map(function (x) { return '<span class="wtag">' + x + '</span>'; }).join('') + '</div>'
            + '<div class="wlinks">'
            + (u ? '<a class="wlink" href="' + u + '" target="_blank">Demo</a>' : '')
            + (g ? '<a class="wlink" href="' + g + '" target="_blank">GitHub</a>' : '')
            + '</div></div>';
        var grid = document.getElementById('wgrid');
        var add = document.getElementById('addCard');
        if (grid && add) grid.insertBefore(card, add);
        setTimeout(function () { applyTilt(card); addRipple(card); }, 60);
        ['pT', 'pD', 'pS', 'pU', 'pG'].forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
        closeModal(); showToast('Added successfully!');
    }
    window.addProj = addProj;

    /* THEME */
    var themeBtn = document.getElementById('themeBtn');
    function applyTheme(d) { document.body.classList.toggle('dark', d); }
    if (themeBtn) themeBtn.addEventListener('click', function () {
        var d = !document.body.classList.contains('dark');
        applyTheme(d); localStorage.setItem('th', d ? '1' : '0');
    });
    applyTheme(localStorage.getItem('th') === '1');
    window.toggleTheme = function () { if (themeBtn) themeBtn.click(); };

    /* FAB */
    var fab = document.getElementById('fab');
    window.addEventListener('scroll', function () { if (fab) fab.classList.toggle('show', scrollY > 400); }, { passive: true });
    function scrollToTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); }
    window.scrollToTop = scrollToTop;

    /* INPUT FOCUS COLOR */
    document.querySelectorAll('.fg input,.fg textarea').forEach(function (inp) {
        inp.addEventListener('focus', function () { var l = inp.previousElementSibling; if (l && l.tagName === 'LABEL') l.style.color = 'var(--acc)'; });
        inp.addEventListener('blur', function () { var l = inp.previousElementSibling; if (l && l.tagName === 'LABEL') l.style.color = ''; });
    });


    /* EMAIL */
    function sendMail() {
        var u = 'somchai'; var d = 'email.com';
        window.location.href = 'mai' + 'lto:' + u + '@' + d;
    }
})();

function scrollTopAndHighlight() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    var d = window.scrollY > 300 ? 700 : 80;
    setTimeout(function () {
        document.querySelectorAll('.chips .chip').forEach(function (c, i) {
            setTimeout(function () {
                c.classList.remove('highlight'); void c.offsetWidth;
                c.classList.add('highlight');
                setTimeout(function () { c.classList.remove('highlight'); }, 900);
            }, i * 160);
        });
    }, d);
}


/* ═══ WORK POPUP ═══ */
var WDATA = [
    {
        name: 'ระบบจองห้องพิเศษ รพ.', cat: 'Internship Project',
        tags: ['HTML/CSS', 'JavaScript', 'PHP', 'SQL', 'XAMPP'],
        imgs: [
            {
                src: './asset/Project_Intern_Image/Capture5.PNG', 
                bg: 'linear-gradient(135deg,#e2f2e8,#b8dce8)', 
                abbr: 'หน้าหลัก',
                title: 'หน้าแสดงเมนู ประเภทการเข้าใช้งาน',
                desc: 'ผู้ใช้สามารถเลือก ประเภทการเข้าใช้งานได้ โดยมี 2 ตัวเลือก คือ ผู้ป่วยและ เจ้าหน้าที่/พยาบาล'
            },
            {
                src: './asset/Project_Intern_Image/Capture.PNG', 
                bg: 'linear-gradient(135deg,#d0eef5,#a2d4e5)', 
                abbr: 'หน้าล็อคอิน',
                title: 'หน้าล็อคอิน สำหรับเจ้าหน้าที่/พยาบาล',
                desc: 'กรอกข้อมูล username และ password เพื่อเข้าใช้งานระบบ'
            },
            {
                src: './asset/Project_Intern_Image/Capture12.PNG', 
                bg: 'linear-gradient(135deg,#c5e8d5,#90cba8)', 
                abbr: 'Admin',
                title: 'หน้าจัดการเตียง',
                desc: 'แสดง เดชบอร์ด รายการเตียง  ผู้ใช้สามารถจัดการเตียง และ จองเตียงโดยตรงผ่านการกรอก เลข HN ของผุ้ป่วย '
            },
            {
                src: './asset/Project_Intern_Image/Capture13.PNG', 
                bg: 'linear-gradient(135deg,#e0f5ea,#a8d8bc)', 
                abbr: 'Admin',
                title: 'หน้าจัดการคิว',
                desc: 'แสดง เดชบอร์ด รายการคิว และ ผู้ใช้สามารถ ปิดเคส ยกเลิกเคส เคลียร์เคส รวมถึงจองเตียงให้ผู้ป่วยตามคิว ผ่านหน้าจัดการคิวได้'
            },
            {
                src: './asset/Project_Intern_Image/Capture11.PNG', 
                bg: 'linear-gradient(135deg,#e0f5ea,#a8d8bc)', 
                abbr: 'Supper_Admin',
                title: 'หน้าจัดการสิทธิผู้ใช้งาน',
                desc: 'สามารถจัดการสิทธิผู้ใช้งานในระบบ ได้ 3 ระดับ คือ Staff, Admin และSuper_Admin'
            }
        ]
    },
    {
        name: 'Line Chatbot — โปรเจกต์จบ', cat: 'Senior Project',
        tags: ['Dialogflow', 'Firebase', 'Line OA', 'PyThaiNLP', 'Python'],
        imgs: [
            {
                src: './asset/Senior_Project_Image/4.jpg', 
                bg: 'linear-gradient(135deg,#e2faf0,#b5f0d0)', 
                abbr: 'หน้าเมนู Line Chatbot',
                title: 'ตัวอย่างเมนู Line Chatbot',
                desc: 'ตัวอย่างเมนู 4 เมนูหลัก คือ รู้จักภาควิชา แนะนำการลงทะเบียนเรียน แนะนำสายการเรียนและแนะนำรายวิชา '
            },
            {
                src: './asset/Senior_Project_Image/6.PNG', 
                bg: 'linear-gradient(135deg,#d8f5e8,#a0e0c0)', 
                abbr: 'หน้าเริ่มต้น',
                title: 'หน้าเริ่มต้นสนทนา',
                desc: 'หน้าเริ่มต้นสนทนา หลังจากผู้ใช้แอดเพื่อน บอทแสดงข้อความความทักทายผู้ใช้พร้อมแสดงแถบเมนูให้ผู้ใช้เลือก'
            },
            {
                src: './asset/Senior_Project_Image/3.jpg', 
                bg: 'linear-gradient(135deg,#c5eedd,#85d4ae)', 
                abbr: 'ตัวอย่างผลลัพธ์',
                title: 'ตัวอย่างการตอบกลับของแชทบอท เมนู รู้จักหลักสูตรในภาควิชา',
                desc: 'แชทบอทแสดงรายละเอียดข้อมูลเบื้องต้น ของภาควิชา'
            },
            {
                src: './asset/Senior_Project_Image/1.jpg', 
                bg: 'linear-gradient(135deg,#b8ecd5,#70c89a)', 
                abbr: 'ตัวอย่างผลลัพธ์',
                title: 'ตัวอย่างการตอบกลับของแชทบอท เมนู แนะนำสายการเรียน',
                desc: 'แชทบอทแสดงรายละเอียด รายวิชาที่ควรเรียนในสายการเรียน Network'
            }
        ]
    }
];

var _wCur = 0, _wData = null;

function openWpop(i) {
    _wData = WDATA[i]; _wCur = 0;
    var bg = document.getElementById('wpopBg');
    if (!bg) return;
    bg.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('wpopBadge').textContent = _wData.cat;
    document.getElementById('wpopName').textContent = _wData.name;
    document.getElementById('wpopTags').innerHTML = _wData.tags.map(function (t) {
        return '<span class="wpop-tag">' + t + '</span>';
    }).join('');
    document.getElementById('wpopSlides').innerHTML = _wData.imgs.map(function (img) {
        if (img.src) return '<div class="wpop-slide" style="background:' + img.bg + '"><img src="' + img.src + '" alt="' + img.title + '"></div>';
        return '<div class="wpop-slide" style="background:' + img.bg + '">'
            + '<div class="wpop-ph">'
            + '<div class="wpop-ph-icon"><svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>'
            + '<div class="wpop-ph-abbr">' + img.abbr + '</div>'
            + '<div class="wpop-ph-sub">ใส่ Screenshot ที่นี่</div>'
            + '</div></div>';
    }).join('');
    document.getElementById('wpopThumbs').innerHTML = _wData.imgs.map(function (img, n) {
        var cls = 'wpop-thumb' + (n === 0 ? ' active' : '');
        if (img.src) return '<div class="' + cls + '" onclick="wSlide(' + n + ')"><img src="' + img.src + '" alt="' + img.title + '"></div>';
        return '<div class="' + cls + '" onclick="wSlide(' + n + ')" style="background:' + img.bg + '">'
            + '<div class="wpop-thumb-ph">' + img.abbr + '</div></div>';
    }).join('');
    wSlide(0);
}

function wSlide(n) {
    if (!_wData) return;
    _wCur = n;
    document.getElementById('wpopSlides').style.transform = 'translateX(-' + (n * 100) + '%)';
    document.getElementById('wpopCounter').textContent = (n + 1) + ' / ' + _wData.imgs.length;
    document.querySelectorAll('.wpop-thumb').forEach(function (th, i) { th.classList.toggle('active', i === n); });
    var img = _wData.imgs[n];
    document.getElementById('wpopCapNum').textContent = 'ภาพที่ ' + (n + 1);
    document.getElementById('wpopCapTitle').textContent = img.title;
    document.getElementById('wpopCapDesc').textContent = img.desc;
}

function closeWpop() {
    var bg = document.getElementById('wpopBg');
    if (bg) bg.classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    var wpBg = document.getElementById('wpopBg');
    var wpCl = document.getElementById('wpopClose');
    var arrL = document.getElementById('wpopArrL');
    var arrR = document.getElementById('wpopArrR');
    if (wpCl) wpCl.addEventListener('click', closeWpop);
    if (wpBg) wpBg.addEventListener('click', function (e) { if (e.target === wpBg) closeWpop(); });
    if (arrL) arrL.addEventListener('click', function () { if (_wData) wSlide((_wCur - 1 + _wData.imgs.length) % _wData.imgs.length); });
    if (arrR) arrR.addEventListener('click', function () { if (_wData) wSlide((_wCur + 1) % _wData.imgs.length); });

    /* mob fab theme */
    var mft = document.getElementById('mobFabTheme');
    if (mft) mft.addEventListener('click', function () {
        var dark = !document.body.classList.contains('dark');
        document.body.classList.toggle('dark', dark);
        localStorage.setItem('th', dark ? '1' : '0');
    });

    /* profile card popup */
    var pcBg = document.getElementById('pcpopBg');
    var pcCls = document.getElementById('pcpopClose');
    var pCard = document.getElementById('pcard');
    function openPop() {
        var av = document.getElementById('avImg'), pi = document.getElementById('pcpopImg'), ph = document.getElementById('pcpopPh');
        if (av && av.src && av.style.display !== 'none') { if (pi) { pi.src = av.src; pi.style.display = 'block'; } if (ph) ph.style.display = 'none'; }
        else { if (pi) pi.style.display = 'none'; if (ph) ph.style.display = 'block'; }
        pcBg.classList.add('open'); document.body.style.overflow = 'hidden';
    }
    function closePop() { pcBg.classList.remove('open'); document.body.style.overflow = ''; }
    if (pCard) pCard.addEventListener('click', openPop);
    if (pcCls) pcCls.addEventListener('click', closePop);
    if (pcBg) pcBg.addEventListener('click', function (e) { if (e.target === pcBg) closePop(); });

    /* keyboard + swipe */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeWpop(); closePop(); }
        if (wpBg && wpBg.classList.contains('open') && _wData) {
            if (e.key === 'ArrowLeft') wSlide((_wCur - 1 + _wData.imgs.length) % _wData.imgs.length);
            if (e.key === 'ArrowRight') wSlide((_wCur + 1) % _wData.imgs.length);
        }
    });
    var gallery = document.querySelector('.wpop-main'), tsX = 0;
    if (gallery) {
        gallery.addEventListener('touchstart', function (e) { tsX = e.touches[0].clientX; }, { passive: true });
        gallery.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - tsX;
            if (Math.abs(dx) > 40 && _wData) {
                if (dx < 0) wSlide((_wCur + 1) % _wData.imgs.length);
                else wSlide((_wCur - 1 + _wData.imgs.length) % _wData.imgs.length);
            }
        }, { passive: true });
    }
});
