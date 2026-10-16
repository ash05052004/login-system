(function(){
    var rules = [
      { no: "No. 01", text: "Check-in is from 2:00 PM, check-out is by 12:00 NN. Please message us if you need to arrange a different time." },
      { no: "No. 02", text: "Quiet hours are observed from 10:00 PM to 7:00 AM out of respect for the other tenants." },
      { no: "No. 03", text: "No smoking inside the rooms or common areas. Designated outdoor areas are available." },
      { no: "No. 04", text: "Visitors are welcome but must be registered with the caretaker before entering the apartment." },
      { no: "No. 05", text: "Please report any damages or maintenance issues right away so we can address them quickly." }
    ];
    var index = 0;
    var ruleNo = document.getElementById('ruleNo');
    var ruleText = document.getElementById('ruleText');
    var display = document.getElementById('ruleDisplay');
    var dotsWrap = document.getElementById('ruleDots');

    rules.forEach(function(_, i){
      var dot = document.createElement('span');
      dot.className = 'rule-dot' + (i === 0 ? ' active' : '');
      dot.addEventListener('click', function(){ goTo(i); });
      dotsWrap.appendChild(dot);
    });

    function render(){
      display.classList.add('fade-out');
      setTimeout(function(){
        ruleNo.textContent = rules[index].no;
        ruleText.textContent = rules[index].text;
        Array.prototype.forEach.call(dotsWrap.children, function(dot, i){
          dot.classList.toggle('active', i === index);
        });
        display.classList.remove('fade-out');
      }, 150);
    }
    function goTo(i){ index = (i + rules.length) % rules.length; render(); }

    document.getElementById('rulePrev').addEventListener('click', function(){ goTo(index - 1); });
    document.getElementById('ruleNext').addEventListener('click', function(){ goTo(index + 1); });

    /* Photo slider */
    var photos = ["interior1.png", "interior2.png", "interior3.png"];
    var photoIndex = 0;
    var photoSlider = document.getElementById('photoSlider');
    var photoDotsWrap = document.getElementById('photoDots');
    var photoPrev = document.getElementById('photoPrev');
    var photoNext = document.getElementById('photoNext');
    var photoFrame = document.getElementById('aboutPhotos');

    if (photoSlider && photos.length) {
      photoFrame && photoFrame.classList.add('has-slides');
      photos.forEach(function(name, i){
        var img = document.createElement('img');
        img.className = 'photo-slide' + (i === 0 ? ' active' : '');
        img.src = encodeURI('Images/' + name);
        img.alt = 'Apartment photo ' + (i + 1);
        photoSlider.appendChild(img);

        var dot = document.createElement('span');
        dot.className = (i === 0 ? 'active' : '');
        dot.addEventListener('click', function(){ goToPhoto(i); });
        photoDotsWrap.appendChild(dot);
      });

      renderPhoto();
      photoPrev && photoPrev.addEventListener('click', function(){ goToPhoto(photoIndex - 1); });
      photoNext && photoNext.addEventListener('click', function(){ goToPhoto(photoIndex + 1); });
    }

    function renderPhoto(){
      var slides = photoSlider.querySelectorAll('.photo-slide');
      var dots = photoDotsWrap.querySelectorAll('span');
      slides.forEach(function(s, i){ s.classList.toggle('active', i === photoIndex); });
      dots.forEach(function(d, i){ d.classList.toggle('active', i === photoIndex); });
    }
    function goToPhoto(i){ photoIndex = (i + photos.length) % photos.length; renderPhoto(); }

    var navLinks = Array.prototype.slice.call(document.querySelectorAll('header nav a'));
    var underline = document.querySelector('.nav-underline');

    if (navLinks.length && underline) {
      function updateNavUnderline(activeLink) {
        var activeNavLink = activeLink || document.querySelector('header nav a.active') || navLinks[0];
        var navRect = activeNavLink.closest('nav').getBoundingClientRect();
        var linkRect = activeNavLink.getBoundingClientRect();
        underline.style.left = (linkRect.left - navRect.left) + 'px';
        underline.style.top = (linkRect.bottom - navRect.top - 2) + 'px';
        underline.style.width = linkRect.width + 'px';
      }
      navLinks.forEach(function(link){
        link.addEventListener('click', function(){
          navLinks.forEach(function(item){ item.classList.toggle('active', item === link); });
          updateNavUnderline(link);
        });
      });
      updateNavUnderline(document.querySelector('header nav a.active') || navLinks[0]);
      window.addEventListener('resize', function(){
        updateNavUnderline(document.querySelector('header nav a.active') || navLinks[0]);
      });
    }
  })();