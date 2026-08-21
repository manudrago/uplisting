jQuery(function ($) {

    // --- Search handler ---
    $('#rental-filter-search').on('click', function (e) {
        e.preventDefault();
        runAvailabilitySearch();
    });

    // --- Clear filters ---
    $('#rental-filter-clear').on('click', function (e) {
        e.preventDefault();
        $('#rental-city-select').val('');
        $('#rental-guests-select').val('');
        $('#rental-start-date,#rental-end-date').val('');
        
        // Hide no results message
        $('#rental-no-results').hide();
        
        // Clear total prices and show only today's day rates
        $('.rental-card').each(function() {
            $(this).find('.rental-total').empty();
            $(this).find('.rental-minstay').empty();
            $(this).find('.rental-nights').empty();
        });
        
        runAvailabilitySearch(true);
    });

    // --- Handle pagination clicks (AJAX) ---
    $(document).on('click', '.rental-pagination a.page-numbers, .rental-pagination span.page-numbers', function (e) {
        e.preventDefault();

        if ($(this).hasClass('current')) return;

        let href = $(this).attr('href') || '';
        let paged = 1;

        const pagedMatch = href.match(/paged=([0-9]+)/) || href.match(/page\/([0-9]+)/);
        if (pagedMatch) {
            paged = parseInt(pagedMatch[1]);
        } else {
            const textNum = parseInt($(this).text());
            if (!isNaN(textNum)) paged = textNum;
        }

        runAvailabilitySearch(false, paged);
        $('html, body').animate({ scrollTop: $(".rental-container").offset().top - 100 }, 400);
    });

    // --- Main AJAX availability fetch ---
    async function runAvailabilitySearch(reset = false, paged = 1) {
        const city = $('#rental-city-select').val();
        const guests = $('#rental-guests-select').val();
        const start = $('#rental-start-date').val();
        const end = $('#rental-end-date').val();

        let availableIds = [];
        let pricesMap = {};
        let dayRateMap = {};
        let minStayMap = {};
        let nightsMap = {};
        let datesSelected = !!(start && end);

        // Only fetch availability if dates are provided
        if (datesSelected) {
            try {
                const url = `${rentalAjax.rest_url}?check_in=${start}&check_out=${end}&number_of_guests=${guests || ''}&city=${city || ''}`;
                const res = await fetch(url);
                const data = await res.json();

                if (data && data.data) {
                    availableIds = data.data.map(p => parseInt(p.id));
                    
                    data.data.forEach(p => {
                        const id = parseInt(p.id);
                        const avgRate = p.attributes?.average_day_rate || null;
                        const total = p.attributes?.total_price || null;
                        const minStay = p.attributes?.min_stay || null;
                        const nights = p.attributes?.nights || null;

                        if (total) pricesMap[id] = total;
                        var __bd = (p.attributes && p.attributes.daily_breakdown) ? p.attributes.daily_breakdown : [];
                        var __sub = p.attributes ? p.attributes.subtotal : null;
                        var __subBase = p.attributes ? p.attributes.subtotal_base : null;
                        if (__bd.length) {
                            var __minBase = Math.min.apply(null, __bd.map(function (x) { return x.rate; }));
                            var __ratio = (__sub && __subBase) ? (__sub / __subBase) : 1;
                            dayRateMap[id] = Math.round(__minBase * __ratio * 100) / 100;
                        } else if (avgRate) {
                            dayRateMap[id] = avgRate;
                        }
                        if (minStay) minStayMap[id] = minStay;
                        if (nights) nightsMap[id] = nights;
                    });
                }
                
                // If dates are selected but no properties available, show message immediately
                if (availableIds.length === 0) {
                    $('#rental-no-results').fadeIn();
                    $('#rental-results .rental-grid').html('<p class="no-results">No properties available for the selected dates.</p>');
                    $('.rental-pagination').empty().hide();
                    $('#rental-map').hide();
                    return; // Stop here, don't make AJAX call
                }
            } catch (e) {
                console.error('Availability fetch failed', e);
            }
        }

        $.ajax({
            url: rentalAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'rental_filter',
                nonce: rentalAjax.nonce,
                city: city,
                guests: guests,
                available_ids: availableIds,
                paged: paged
            },
            success: function (html) {
                const parsed = $('<div>').html(html);
                let newGrid = parsed.find('#rental-results .rental-grid').html();
                let newPagination = parsed.find('.rental-pagination').html();

                if (!newGrid && parsed.find('.rental-grid').length) {
                    newGrid = parsed.find('.rental-grid').html();
                }
                if (!newPagination && parsed.find('.rental-pagination').length) {
                    newPagination = parsed.find('.rental-pagination').html();
                }

                if (!newGrid) {
                    console.error("No .rental-grid found in AJAX response");
                    return;
                }

                const grid = $('#rental-results .rental-grid');
                grid.css({ 'opacity': 0.4, 'transition': 'opacity 0.3s ease' });

                setTimeout(() => {
                    grid.html(newGrid);
                    
                    // Check if we have any properties when dates are selected
                    const hasProperties = grid.find('.rental-card').length > 0;
                    
                    if (datesSelected && !hasProperties) {
                        // Show "no results" message
                        $('#rental-no-results').fadeIn();
                        $('#rental-map').hide();
                        grid.html('<p class="no-results">No properties available for the selected dates.</p>');
                    } else {
                        // Hide "no results" message
                        $('#rental-no-results').hide();
                        $('#rental-map').show();
                    }
                    
                    grid.css('opacity', 1).show();

                    // Redraw the map from the same filtered result the grid was built from,
                    // otherwise every pin stays on screen whatever the visitor searched for.
                    rebuildMap(readMapProps(parsed));

                    let paginationContainer = $('.rental-pagination');
                    if (!paginationContainer.length) {
                        $('#rental-results .rental-grid').after('<div class="rental-pagination"></div>');
                        paginationContainer = $('.rental-pagination');
                    }
                    
                    if (newPagination && $.trim(newPagination).length) {
                        paginationContainer.html(newPagination).show();
                    } else {
                        paginationContainer.empty().hide();
                    }

                    $('.rental-pagination .page-numbers').removeClass('current');
                    $(`.rental-pagination a.page-numbers[href*="paged=${paged}"], 
                       .rental-pagination a.page-numbers[href*="/page/${paged}"]`)
                        .addClass('current')
                        .attr('aria-current', 'page');

                    const totalPages = $('.rental-pagination .page-numbers:not(.next, .prev)').length;
                    if (paged >= totalPages) {
                        $('.rental-pagination .next').hide();
                    } else {
                        $('.rental-pagination .next').show();
                    }

                    if (paged <= 1) {
                        $('.rental-pagination .prev').hide();
                    } else {
                        $('.rental-pagination .prev').show();
                    }

                    // Update prices ONLY if dates are selected
                    if (datesSelected) {
                        for (const id in pricesMap) {
                            const total = pricesMap[id];
                            const nights = nightsMap[id];
                            const card = $(`.rental-card[data-id="${id}"]`);
                            
                            if (card.length && total) {
                                const nightsText = nights ? ` (${nights} ${nights === 1 ? 'night' : 'nights'})` : '';
                                card.find('.rental-total').html(`<strong>Total: £${Math.round(total).toLocaleString()}${nightsText}</strong>`);
                            }
                        }

                        for (const id in dayRateMap) {
                            const rate = dayRateMap[id];
                            const card = $(`.rental-card[data-id="${id}"]`);
                            if (card.length && rate) {
                                card.find('.rental-dayrate').text(`From £${Math.round(rate).toLocaleString()} / night`);
                            }
                        }

                        for (const id in minStayMap) {
                            const nights = minStayMap[id];
                            const card = $(`.rental-card[data-id="${id}"]`);
                            if (card.length && nights) {
                                card.find('.rental-minstay').text(`Min stay: ${nights} ${nights === 1 ? 'night' : 'nights'}`);
                            }
                        }
                    } else {
                        // When no dates selected, show today's rate only
                        $('.rental-card').each(function() {
                            $(this).find('.rental-total').empty();
                            $(this).find('.rental-minstay').empty();
                            $(this).find('.rental-nights').empty();
                        });
                    }

                    if (hasProperties) {
                        rebuildMap();
                    }
                }, 200);
            }
        });
    }

    // Map points for a given document or parsed AJAX response. Falls back to the bootstrap value
    // printed on first load.
    function readMapProps($scope) {
        try {
            const raw = $scope && $scope.find ? $scope.find('#rental-map-data').attr('data-props') : null;
            if (raw) return JSON.parse(raw);
        } catch (e) {
            console.error('Could not read map data', e);
        }
        console.warn('rental map: #rental-map-data missing, falling back to the full property set');
        return window.rentalAllProperties || [];
    }

    // Marker numbers are fixed per property, assigned once from the full unfiltered set at page
    // load. A filtered map then keeps each pin's original number, so pins and cards always agree —
    // renumbering the visible subset would leave the two out of step.
    //
    // Captured now, before any search: an AJAX response carries its own (filtered)
    // window.rentalAllProperties, and jQuery evaluates that script while parsing, so the global is
    // not a safe baseline later on.
    const numberById = {};
    (function assignStableNumbers() {
        readMapProps($(document)).forEach(function (p, i) {
            numberById[String(p.id)] = i + 1;
        });
    })();

    // --- Leaflet map: shows the properties matching the current search, with image popups ---
    function rebuildMap(propsOverride) {
        if (typeof L === 'undefined' || !$('#rental-map').length) return;

        if (window.rentalMap) {
            window.rentalMap.remove();
        }

        const map = L.map('rental-map');
        window.rentalMap = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const props = propsOverride || window.rentalAllProperties || [];
        const markers = [];
        const markersById = {};
        let fallbackNumber = Object.keys(numberById).length + 1;

        props.forEach(function (p) {
            const lat = parseFloat(p.lat);
            const lng = parseFloat(p.lng);
            if (!lat || !lng) return;

            const num = numberById[String(p.id)] || fallbackNumber++;

            const numberIcon = L.divIcon({
                className: 'custom-marker',
                html: '<div class="marker-pin" data-id="' + p.id + '"><span class="marker-number">' + num + '</span></div>',
                iconSize: [40, 40],
                iconAnchor: [20, 40],
                popupAnchor: [0, -40]
            });

            const imgHtml = p.img ? '<img class="rental-popup-img" src="' + p.img + '" alt="">' : '';
            const marker = L.marker([lat, lng], { icon: numberIcon })
                .addTo(map)
                .bindPopup('<div style="min-width: 200px;">' + imgHtml +
                    '<strong style="font-size: 14px; display: block; margin-bottom: 4px;">' + p.title + '</strong>' +
                    '<div style="color: #0073aa; font-weight: 600; margin-bottom: 8px;">' + (p.price || '') + '</div>' +
                    '<a href="' + p.link + '" style="display: inline-block; padding: 6px 12px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px; font-size: 13px;">View Details</a>' +
                    '</div>');

            // Highlight matching card (if it is on the current page) when marker is hovered
            marker.on('mouseover', function () {
                $('.rental-card[data-id="' + p.id + '"]').addClass('highlighted');
            });
            marker.on('mouseout', function () {
                $('.rental-card[data-id="' + p.id + '"]').removeClass('highlighted');
            });

            markersById[String(p.id)] = { marker: marker, num: num };
            markers.push(marker);
        });

        // Number badges on visible cards + card-hover -> marker highlight
        $('.rental-card').each(function () {
            const $card = $(this);
            const propertyId = String($card.data('id'));
            const entry = markersById[propertyId];
            if (!entry) return;

            $card.find('.card-number').remove();
            $card.prepend('<div class="card-number">' + entry.num + '</div>');

            $card.off('mouseenter.rentalmap mouseleave.rentalmap');
            $card.on('mouseenter.rentalmap', function () {
                $('.marker-pin[data-id="' + propertyId + '"]').addClass('highlighted-marker');
                entry.marker.openPopup();
            });
            $card.on('mouseleave.rentalmap', function () {
                $('.marker-pin[data-id="' + propertyId + '"]').removeClass('highlighted-marker');
                entry.marker.closePopup();
            });
        });

        if (markers.length) {
            const group = new L.featureGroup(markers);
            map.fitBounds(group.getBounds(), { padding: [50, 50] });
        } else {
            map.setView([51.505, -0.09], 6);
        }

        setTimeout(() => map.invalidateSize(), 300);
    }

    // Build the map on initial page load
    rebuildMap(readMapProps($(document)));

});
