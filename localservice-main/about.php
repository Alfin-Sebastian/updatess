<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | UrbanServe</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        /* Base Styles */
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
          
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        body.loaded {
            opacity: 1;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        h2 {
            color: #2d3748;
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f76d2b;
        }
        
        /* About Sections */
        .about-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .section-title {
            color: #f76d2b;
            font-size: 1.5rem;
            margin-top: 0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            font-size: 1.8rem;
        }
        
        .about-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        @media (min-width: 768px) {
            .about-content {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .about-text {
            line-height: 1.7;
            color: #4a5568;
        }
        
        .about-image {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .highlight-box {
            background-color: #f0f7ff;
            border-left: 4px solid #f76d2b;
            padding: 20px;
            margin: 20px 0;
            border-radius: 0 4px 4px 0;
        }
        
        .steps-list {
            list-style-type: none;
            padding: 0;
        }
        
        .steps-list li {
            padding: 10px 0;
            border-bottom: 1px dashed #e2e8f0;
            display: flex;
            gap: 15px;
        }
        
        .steps-list li:last-child {
            border-bottom: none;
        }
        
        .step-number {
            background-color: #f76d2b;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-weight: bold;
        }
        
        /* Back Link */
        .back-link-container {
            text-align: center;
            margin-top: 40px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background-color: #f76d2b;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .back-link:hover {
            background-color: #e05b1a;
        }
        
        /* Loading Overlay */
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: white;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            transition: opacity 0.5s ease;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #f76d2b;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="container">
        <h2>About UrbanServe</h2>
        
        <!-- About Company Section -->
        <div class="about-section">
            <h3 class="section-title"><i class="fas fa-building"></i> Our Story</h3>
            <div class="about-content">
                <div class="about-text">
                    <p>UrbanServe was founded in 2020 with a simple mission: to connect people with reliable local service professionals for all their home and business needs.</p>
                    <p>What started as a small platform for home services in one city has grown into a nationwide network of trusted professionals across dozens of service categories.</p>
                    <div class="highlight-box">
                        We believe in making service booking as easy as ordering food online - simple, transparent, and stress-free.
                    </div>
                    <p>Our team is dedicated to ensuring quality service delivery while supporting local businesses and independent professionals in growing their customer base.</p>
                </div>
                <img src="https://images.unsplash.com/photo-1552664730-d307ca884978?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" alt="Our Team" class="about-image">
            </div>
        </div>
        
        <!-- For Customers Section -->
        <div class="about-section">
            <h3 class="section-title"><i class="fas fa-users"></i> For Customers</h3>
            <div class="about-content">
                <img src="https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" alt="Happy Customer" class="about-image">
                <div class="about-text">
                    <p>UrbanServe makes it easy to find and book trusted local service professionals for all your needs:</p>
                    <ul class="steps-list">
                        <li>
                            <span class="step-number">1</span>
                            <span>Search for services in your area and compare providers</span>
                        </li>
                        <li>
                            <span class="step-number">2</span>
                            <span>View profiles, ratings, and service details</span>
                        </li>
                        <li>
                            <span class="step-number">3</span>
                            <span>Book instantly or request a quote</span>
                        </li>
                        <li>
                            <span class="step-number">4</span>
                            <span>Make secure payments and track your service request</span>
                        </li>
                        <li>
                            <span class="step-number">5</span>
                            <span>Rate your experience to help our community</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- For Service Providers Section -->
        <div class="about-section">
            <h3 class="section-title"><i class="fas fa-tools"></i> For Service Providers</h3>
            <div class="about-content">
                <div class="about-text">
                    <p>UrbanServe helps service professionals grow their business by connecting them with customers who need their services:</p>
                    <ul class="steps-list">
                        <li>
                            <span class="step-number">1</span>
                            <span>Create your professional profile showcasing your services</span>
                        </li>
                        <li>
                            <span class="step-number">2</span>
                            <span>Get matched with customers in your service area</span>
                        </li>
                        <li>
                            <span class="step-number">3</span>
                            <span>Manage bookings and communicate with customers</span>
                        </li>
                        <li>
                            <span class="step-number">4</span>
                            <span>Receive secure payments and build your reputation</span>
                        </li>
                        <li>
                            <span class="step-number">5</span>
                            <span>Grow your business with our marketing tools</span>
                        </li>
                    </ul>
                </div>
                <img src="https://images.unsplash.com/photo-1600880292203-757bb62b4baf?ixlib=rb-1.2.1&auto=format&fit=crop&w=500&q=80" alt="Service Professional" class="about-image">
            </div>
        </div>
        
        <!-- Back Link -->
        <div class="back-link-container">
            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Home
            </a>
        </div>
    </div>
<?php include 'footer.php'; ?>

    <script>
        // Simple loading simulation
        window.addEventListener('load', function() {
            setTimeout(function() {
                document.getElementById('loading-overlay').style.opacity = '0';
                document.body.classList.add('loaded');
                setTimeout(function() {
                    document.getElementById('loading-overlay').style.display = 'none';
                }, 500);
            }, 800);
        });
    </script>
</body>
</html>