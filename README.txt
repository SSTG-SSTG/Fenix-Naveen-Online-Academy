Fenix Naveen site (PHP + XML)
1. Open config.php, set ADMIN_PASSWORD and SESSION_SECRET (both required).
2. Upload ALL files to htdocs (keep the data folder and its .htaccess).
3. Make sure the data folder is writable (permission 755, or 775/777 if saving fails).
Pages:
  index.html      home          questions.html  practice questions
  store.html      store         admin.html      add/delete questions (login)
  console.html    add/edit/delete store items (login, same password)
Data: data/questions.xml and data/store.xml (download a backup now and then).
Paid items: the Buy button opens the link set in the console (Razorpay link, Google Form...) or WhatsApp if left empty.
