
<?php
session_start();
require_once 'config.php';

function homepageGetSetting($pdo, $key, $default = '') {
    $stmt = $pdo->prepare(
        "SELECT setting_value FROM settings WHERE setting_key = ?"
    );
    $stmt->execute([$key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ? $result['setting_value'] : $default;
}

$roomOptions = [
    [
        'name' => 'Solo Occupancy',
        'people' => '1 Person',
        'available' => 'solo_available',
        'price' => 'solo_price',
        'per_person' => false,
        'icon' => '♙'
    ],
    [
        'name' => 'Shared Room',
        'people' => '2 Persons',
        'available' => 'two_person_available',
        'price' => 'two_person_price',
        'per_person' => true,
        'icon' => '♧'
    ],
    [
        'name' => 'Shared Room',
        'people' => '3 Persons',
        'available' => 'three_person_available',
        'price' => 'three_person_price',
        'per_person' => true,
        'icon' => '♧'
    ],
    [
        'name' => 'Shared Room',
        'people' => '4 Persons',
        'available' => 'four_person_available',
        'price' => 'four_person_price',
        'per_person' => true,
        'icon' => '♧'
    ]
];

foreach ($roomOptions as &$room) {
    $room['is_available'] =
        homepageGetSetting($pdo, $room['available'], '1') === '1';

    $room['monthly_price'] = max(
        0,
        (float) homepageGetSetting($pdo, $room['price'], '0')
    );
}
unset($room);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dannielle's Apartment</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;500;600;700&family=Karla:wght@400;500;600;700&family=Cormorant+Garamond:ital,wght@1,500;1,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/HStyle.css">

<style>
.hero {
  background:
    linear-gradient(180deg, rgba(0,0,0,0.45) 0%, rgba(0,0,0,0.75) 100%),
    url("Images/Hall.jpg") center / cover no-repeat !important;
  min-height: 88vh;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  color: #EDE3D1;
  padding: 120px 24px 100px;
}

/* Room Availability */
.room-availability {
    background: #F6F1E6;
    padding: 90px 24px;
    color: #2A2118;
}

.room-availability .section-heading {
    text-align: center;
    max-width: 650px;
    margin: 0 auto 45px;
}

.room-availability .section-heading h2 {
    font-family: var(--font-display);
    font-size: clamp(1.8rem, 4vw, 2.8rem);
    font-weight: 300;
    margin: 18px 0;
}

.room-availability .section-heading p {
    color: #6B5D4C;
    line-height: 1.7;
}

.room-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
}

.room-card {
    background: #FBF8F2;
    border: 1px solid rgba(42, 33, 24, 0.14);
    padding: 30px 20px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.room-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(42, 33, 24, 0.10);
}

.room-icon {
    font-size: 2rem;
    margin-bottom: 16px;
}

.room-card h3 {
    font-family: var(--font-display);
    font-size: 1rem;
    font-weight: 500;
    margin: 0 0 8px;
}

.room-occupancy {
    color: #6B5D4C;
    font-size: 0.85rem;
    margin-bottom: 22px;
}

.room-price {
    font-family: var(--font-display);
    font-size: 1.8rem;
    color: #6B4F2A;
    margin-bottom: 5px;
}

.room-price-note {
    color: #6B5D4C;
    font-size: 0.78rem;
    margin-bottom: 20px;
}

.room-status {
    display: inline-block;
    padding: 7px 12px;
    font-size: 0.72rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 20px;
}

.room-status.available {
    background: rgba(63, 125, 75, 0.12);
    color: #367344;
}

.room-status.unavailable {
    background: rgba(139, 74, 43, 0.12);
    color: #8B4A2B;
}

.room-book-btn {
    display: block;
    width: 100%;
    margin-top: auto;
    padding: 12px 10px;
    background: #6B4F2A;
    border: 1px solid #6B4F2A;
    color: #FBF8F2;
    text-decoration: none;
    font-family: var(--font-display);
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    transition: 0.2s ease;
}

.room-book-btn:hover {
    background: transparent;
    color: #6B4F2A;
}

.room-book-btn.disabled {
    background: #EDE3D1;
    border-color: #EDE3D1;
    color: #6B5D4C;
    pointer-events: none;
    cursor: not-allowed;
}

.room-availability .section-cta {
    text-align: center;
    margin-top: 40px;
}

.room-availability .section-cta p {
    color: #6B5D4C;
    font-size: 0.82rem;
    margin-bottom: 20px;
}

@media (max-width: 1000px) {
    .room-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 500px) {
    .room-availability {
        padding: 60px 18px;
    }

    .room-grid {
        grid-template-columns: 1fr;
    }

    .room-card {
        padding: 25px 20px;
    }
}
</style>
</head>
<body>

<header>
  <div class="header-inner">
    <div class="brand">
      <span class="brand-text">✦ DANIELLE'S APARTMENT</span>
    </div>
    <nav>
      <span class="nav-underline" aria-hidden="true"></span>
      <ul>
        <li><a href="#top" class="active">Home</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#availability">Rooms &amp; Rates</a></li>
        <li><a href="#location">Location</a></li>
        <li><a href="#rules">Rules</a></li>
      </ul>
    </nav>
    <div class="nav-cta">
      <a class="btn-ghost" href="login.php">Login</a>
      <a class="btn" href="signup.php">Sign up</a>
    </div>
    <button class="menu-toggle" aria-label="Menu">
      <svg width="24" height="18" viewBox="0 0 24 18"><g stroke="#2A2118" stroke-width="1.6"><line x1="0" y1="1" x2="24" y2="1"/><line x1="0" y1="9" x2="24" y2="9"/><line x1="0" y1="17" x2="24" y2="17"/></g></svg>
    </button>
  </div>
</header>

<section class="hero" id="top">
  <div class="hero-inner">
    <p class="eyebrow">Isulan Municipality &middot; South Cotabato</p>
    <h1>Welcome to <br><strong>Dannielle's Apartment</strong></h1>
    <p>A quiet, comfortable stay just outside the city center &mdash; simple rooms, warm hospitality, and a place that feels like home.</p>
    <a class="btn" href="signup.php">Signup to book a room</a>
  </div>
</section>

<div class="roofline-section">
  <svg viewBox="0 0 1200 56" preserveAspectRatio="none">
    <path d="M0,50 L150,50 L230,10 L310,50 L470,50 L560,14 L620,42 L700,50 L860,50 L940,8 L1020,50 L1200,50"
      fill="none" stroke="#6B4F2A" stroke-width="1.6"/>
  </svg>
</div>

<section class="section" id="about">
  <div class="wrap">
    <div class="about-grid">
      <div class="about-copy">
        <p class="eyebrow">About Us</p>
        <h2 style="font-family:var(--font-display); font-weight:300; font-size:clamp(1.8rem,4vw,2.8rem); line-height:1.1; margin:18px 0 28px;">Details about our Apartment</h2>
        <p>Danielle's Apartment is a premium hybrid housing community located in the heart of Isulan, Sultan Kudarat. Designed specifically for students and professionals, our facility offers a perfect balance of comfort, security, and affordability.</p>
        <p>Our location at Kalawag 2, Capitol Centro puts you within minutes of schools, hospitals, the capitol building, and the public market. Whether you're studying or working, Danielle's Apartment provides a safe, peaceful, and convenient place to call home.</p>
        <p>Room rates start at ₱5,000 for solo occupancy, with discounted rates for shared rooms. Utilities are added to the total monthly cost. Experience modern living in Isulan. Your comfort is our priority.</p>
        <div class="amenities">
          <div class="amenity"><span class="diamond"></span> Free Wi-Fi</div>
          <div class="amenity"><span class="diamond"></span> 24/7 Security</div>
          <div class="amenity"><span class="diamond"></span> 14 Well-Maintained Rooms</div>
          <div class="amenity"><span class="diamond"></span> Parking Space</div>
          <div class="amenity"><span class="diamond"></span> Shared Laundry Area</div>
          <div class="amenity"><span class="diamond"></span> Kitchen Access</div>
        </div>
      </div>
      <div class="photo-frame" id="aboutPhotos">
        <div class="photo-slider" id="photoSlider"></div>
        <div class="photo-controls">
          <button class="pager-btn" id="photoPrev" aria-label="Previous photo">
            <svg width="16" height="16" viewBox="0 0 16 16"><path d="M10 2L4 8l6 6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
          </button>
          <button class="pager-btn" id="photoNext" aria-label="Next photo">
            <svg width="16" height="16" viewBox="0 0 16 16"><path d="M6 2l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
          </button>
        </div>
        <div class="photo-dots" id="photoDots"></div>
      </div>
    </div>
  </div>
</section>

<!-- ROOM AVAILABILITY AND RATES -->
<section class="room-availability" id="availability">
  <div class="wrap">
    <div class="section-heading">
      <p class="eyebrow">Find Your Space</p>
      <h2>Room Availability &amp; Rates</h2>
      <p>Choose the room occupancy that suits your needs. Check our current availability and monthly rates below.</p>
    </div>

    <div class="room-grid">
      <?php foreach ($roomOptions as $room): ?>
        <div class="room-card">
          <div class="room-icon">
            <?php echo htmlspecialchars($room['icon']); ?>
          </div>

          <h3><?php echo htmlspecialchars($room['name']); ?></h3>

          <div class="room-occupancy">
            <?php echo htmlspecialchars($room['people']); ?>
          </div>

          <div class="room-price">
            ₱<?php echo number_format($room['monthly_price']); ?>
          </div>

          <div class="room-price-note">
            <?php echo $room['per_person']
                ? 'Per person / month'
                : 'Per month'; ?>
          </div>

          <?php if ($room['is_available']): ?>
            <span class="room-status available">Available</span>
            <a href="signup.php" class="room-book-btn">Book a Viewing</a>
          <?php else: ?>
            <span class="room-status unavailable">Unavailable</span>
            <span class="room-book-btn disabled" aria-disabled="true">
              Currently Unavailable
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="section-cta">
      <p>Water, electricity, and other utilities are added to the monthly rent.</p>
      <a href="signup.php" class="btn">Create an Account</a>
    </div>
  </div>
</section>

<section class="section location" id="location">
  <div class="wrap">
    <div class="location-grid">
      <div>
        <p class="eyebrow">Where to find us</p>
        <h2 style="font-family:var(--font-display); font-weight:300; font-size:clamp(1.8rem,4vw,2.8rem); line-height:1.1; margin:18px 0 28px;">Just outside Isulan, easy to reach</h2>
        <p>Located in a quiet and accessible area of Isulan, Sultan Kudarat — perfect for those who want peace and convenience combined.</p>
        <div class="address">
          Kalawag 2,<br>
          Capitol Centro<br>
          Isulan, Sultan Kudarat
        </div>
        <a class="btn btn-outline" href="https://www.google.com/maps?q=6.6344167,124.6114722" target="_blank" rel="noopener noreferrer">
          Get directions
        </a>
      </div>
      <div class="map-frame">
        <iframe src="https://www.google.com/maps?q=6.6344167,124.6114722&z=14&output=embed" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen title="Location of Dannielle's Apartment"></iframe>
      </div>
    </div>
  </div>
</section>

<section class="section" id="rules">
  <div class="wrap">
    <div class="section-head rules-head-center">
      <h2>Rules &amp; Regulations</h2>
      <p class="eyebrow" style="margin-top:14px; text-align:center;">Please Note</p>
    </div>
    <div class="rule-pager">
      <button class="pager-btn" id="rulePrev" aria-label="Previous rule">
        <svg width="16" height="16" viewBox="0 0 16 16"><path d="M10 2L4 8l6 6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
      </button>
      <div class="rule-display" id="ruleDisplay">
        <div class="rule-num"><em>Rule</em> <span id="ruleNo">No. 01</span></div>
        <p id="ruleText">Check-in is from 2:00 PM, check-out is by 12:00 NN. Please message us if you need to arrange a different time.</p>
      </div>
      <button class="pager-btn" id="ruleNext" aria-label="Next rule">
        <svg width="16" height="16" viewBox="0 0 16 16"><path d="M6 2l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
      </button>
    </div>
    <div class="rule-dots" id="ruleDots"></div>
  </div>
</section>

<section class="cta-band" id="signup">
  <p class="eyebrow">Ready when you are</p>
  <h2>Sign up to book your room today</h2>
  <a class="btn" href="signup.php">Create an account</a>
</section>

<footer id="login">
  <div class="wrap">
    <div class="footer-grid">
      <div class="footer-brand">
        <span class="brand-text">✦ DANIELLE'S APARTMENT</span>
        <p>A quiet, comfortable stay in Isulan &mdash; simple rooms, warm hospitality.</p>
      </div>
      <div>
        <h4>Explore</h4>
        <ul>
          <li><a href="#top">Home</a></li>
          <li><a href="#about">About</a></li>
          <li><a href="#availability">Rooms &amp; Rates</a></li>
          <li><a href="#location">Location</a></li>
          <li><a href="#rules">Rules</a></li>
        </ul>
      </div>
      <div>
        <h4>Account</h4>
        <ul>
          <li><a href="signup.php">Sign up</a></li>
          <li><a href="login.php">Login</a></li>
          <li><a href="#location">Contact us</a></li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; 2026 Dannielle's Apartment. All rights reserved.</span>
      <span>Kalawag 2, Capitol Centro Isulan, Sultan Kudarat</span>
    </div>
  </div>
</footer>

<script>
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

    function goTo(i){
      index = (i + rules.length) % rules.length;
      render();
    }

    document.getElementById('rulePrev').addEventListener('click', function(){
      goTo(index - 1);
    });

    document.getElementById('ruleNext').addEventListener('click', function(){
      goTo(index + 1);
    });

    var photos = ["interior1.png", "interior2.png", "interior3.png"];
    var photoIndex = 0;
    var photoSlider = document.getElementById('photoSlider');
    var photoDotsWrap = document.getElementById('photoDots');
    var photoPrev = document.getElementById('photoPrev');
    var photoNext = document.getElementById('photoNext');
    var photoFrame = document.getElementById('aboutPhotos');

    if (photoSlider && photos.length) {
      if (photoFrame) photoFrame.classList.add('has-slides');

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

      if (photoPrev) {
        photoPrev.addEventListener('click', function(){
          goToPhoto(photoIndex - 1);
        });
      }

      if (photoNext) {
        photoNext.addEventListener('click', function(){
          goToPhoto(photoIndex + 1);
        });
      }
    }

    function renderPhoto(){
      var slides = photoSlider.querySelectorAll('.photo-slide');
      var dots = photoDotsWrap.querySelectorAll('span');

      slides.forEach(function(s, i){
        s.classList.toggle('active', i === photoIndex);
      });

      dots.forEach(function(d, i){
        d.classList.toggle('active', i === photoIndex);
      });
    }

    function goToPhoto(i){
      photoIndex = (i + photos.length) % photos.length;
      renderPhoto();
    }

    var navLinks = Array.prototype.slice.call(
      document.querySelectorAll('header nav a')
    );
    var underline = document.querySelector('.nav-underline');

    if (navLinks.length && underline) {
      function updateNavUnderline(activeLink) {
        var activeNavLink = activeLink ||
          document.querySelector('header nav a.active') ||
          navLinks[0];

        var navRect = activeNavLink.closest('nav').getBoundingClientRect();
        var linkRect = activeNavLink.getBoundingClientRect();

        underline.style.left = (linkRect.left - navRect.left) + 'px';
        underline.style.top = (linkRect.bottom - navRect.top - 2) + 'px';
        underline.style.width = linkRect.width + 'px';
      }

      navLinks.forEach(function(link){
        link.addEventListener('click', function(){
          navLinks.forEach(function(item){
            item.classList.toggle('active', item === link);
          });
          updateNavUnderline(link);
        });
      });

      updateNavUnderline(
        document.querySelector('header nav a.active') || navLinks[0]
      );

      window.addEventListener('resize', function(){
        updateNavUnderline(
          document.querySelector('header nav a.active') || navLinks[0]
        );
      });
    }
})();
</script>

</body>
</html>
