const menuToggle = document.querySelector('.menu-toggle');
        const sidebar = document.querySelector('.sidebar');
        const main = document.querySelector('.main');

        // Toggle sidebar
        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();

            if (window.innerWidth <= 768) {
                // MOBILE
                sidebar.classList.toggle('open');
            } else {
                // DESKTOP
                sidebar.classList.toggle('collapsed');
                main.classList.toggle('expanded');
            }
        });

        // Tutup sidebar jika klik di luar (mobile)
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });