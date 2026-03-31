
<?php
// Load current OMDb API key from the config
include('../config/omdb.php');

// Function to fetch data from OMDb API
function fetchOmdbData($type = 'movie', $page = 1) {
    global $omdb_api_key;
    $url = "http://www.omdbapi.com/?apikey={$omdb_api_key}&type={$type}&page={$page}";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

// Check if we're paginating and if the user wants movies or TV shows
$type = isset($_GET['type']) ? $_GET['type'] : 'movie';
$page = isset($_GET['page']) ? $_GET['page'] : 1;

// Fetch Movies or TV Shows
$items = fetchOmdbData($type, $page);

// Handle pagination (20 items per page)
$perPage = 20;
$start = ($page - 1) * $perPage;
$itemsToDisplay = array_slice($items['Search'], $start, $perPage);

// Show the items on the page
foreach ($itemsToDisplay as $item) {
    echo "<div class='content-grid-item'>";
    echo "<img src='{$item['Poster']}' alt='{$item['Title']}' />";
    echo "<h2>{$item['Title']}</h2>";
    echo "<p>{$item['Year']}</p>";
    echo "</div>";
}

// If there are more items, show the "Load More" button
if (count($items['Search']) > $start + $perPage) {
    echo "<button class='load-more' data-type='{$type}' data-page='" . ($page + 1) . "'>Load More</button>";
}
?>

<script>
document.querySelector('.load-more').addEventListener('click', function() {
    var type = this.getAttribute('data-type');
    var page = this.getAttribute('data-page');

    // Fetch the next set of data
    fetch(`?type=${type}&page=${page}`)
        .then(response => response.text())
        .then(data => {
            // Append the new items to the grid
            document.querySelector('.content-grid').innerHTML += data;
        });
});
</script>
