# WandaScore

**WandaScore** is a MediaWiki extension that provides AI-powered content quality scoring and review for wiki pages. It leverages the [Wanda extension](https://www.mediawiki.org/wiki/Extension:Wanda) to analyze pages based on multiple quality factors and displays an easy-to-understand score.

## Features

- **🎯 Comprehensive Content Analysis**: Reviews pages based on 5 key factors:
  - **Bias Detection**: Identifies potential bias in content
  - **LLM-Generated Content Detection**: Detects AI-generated content
  - **Language Quality**: Evaluates clarity and professionalism
  - **Grammar & Spelling**: Identifies grammatical errors
  - **Conciseness**: Assesses verbosity and redundancy

- **📊 Visual Score Tile**: Displays a floating score tile on pages showing the overall quality score (0-100)
- **🔍 Detailed Review Page**: Comprehensive breakdown of all scoring factors with explanations
- **⚡ Automatic Scoring**: Automatically scores pages when they are created or modified
- **🎨 Modern UI**: Built with Vue 3 and MediaWiki Codex design system
- **⚙️ Configurable**: Control which namespaces to review and customize behavior
- **💾 Cached Results**: Stores scores in database for fast retrieval

## Requirements

- MediaWiki 1.42.0 or later
- PHP 7.4 or later
- **[Wanda extension](https://www.mediawiki.org/wiki/Extension:Wanda)** (required dependency)
- Wanda extension must be properly configured with:
  - An LLM provider (Ollama, OpenAI, Anthropic, Azure, or Gemini)
  - Elasticsearch server (for content indexing)

## Installation

1. **Install the Wanda extension first** if not already installed:
   ```bash
   cd extensions/
   git clone https://gerrit.wikimedia.org/r/mediawiki/extensions/Wanda
   ```

2. **Download and install WandaScore**:
   ```bash
   cd extensions/
   git clone https://gerrit.wikimedia.org/r/mediawiki/extensions/WandaScore
   ```

3. **Add to LocalSettings.php**:
   ```php
   // Load Wanda first (required dependency)
   wfLoadExtension( 'Wanda' );
   
   // Configure Wanda (see Wanda extension documentation)
   $wgWandaLLMProvider = 'ollama';
   $wgWandaLLMModel = 'gemma:2b';
   // ... other Wanda configuration
   
   // Load WandaScore
   wfLoadExtension( 'WandaScore' );
   ```

4. **Run update script**:
   ```bash
   php maintenance/update.php
   ```

5. **Verify installation**:
   Navigate to `Special:Version` to confirm the extension is installed.

## Configuration

Add these configuration variables to your `LocalSettings.php` after loading the extension:

```php
// Namespaces where WandaScore should review pages (default: [0] = main namespace)
$wgWandaScoreNamespaces = [ 0 ];  // Add more namespaces as needed: [ 0, 2, 4, 100 ]

// Enable/disable automatic review on page save (default: true)
$wgWandaScoreAutoReview = true;

// Show/hide the score tile on pages (default: true)
$wgWandaScoreShowTile = true;

// Customize score thresholds for quality levels
$wgWandaScoreThresholds = [
    'excellent' => 90,  // Scores >= 90 are excellent
    'good' => 70,       // Scores >= 70 are good
    'fair' => 50,       // Scores >= 50 are fair
    'poor' => 0         // Scores < 50 are poor
];
```

### Configuration Examples

**Review only main namespace and help pages:**
```php
$wgWandaScoreNamespaces = [ 0, 12 ];  // 0 = Main, 12 = Help
```

**Disable automatic scoring (manual review only):**
```php
$wgWandaScoreAutoReview = false;
```

**Hide score tile but keep detailed reviews available:**
```php
$wgWandaScoreShowTile = false;
```

## Usage

### Score Tile

When enabled, a floating score tile appears in the top-right corner of pages in configured namespaces. The tile shows:
- 📊 A visual indicator
- The overall score (0-100)
- Color-coded by quality:
  - 🟢 Green (90-100): Excellent
  - 🔵 Blue (70-89): Good
  - 🟡 Yellow (50-69): Fair
  - 🔴 Red (0-49): Poor

Click the tile to view the detailed review.

### Detailed Review Page

Access detailed reviews in two ways:

1. **Click the score tile** on any page
2. **Navigate to** `Special:WandaScore` and enter a page title

The review page displays:
- Overall score with quality indicator
- Breakdown of all 5 scoring factors
- Detailed AI-generated explanations for each factor
- Timestamp of the last review
- Refresh button to regenerate the score

### Automatic Scoring

When `$wgWandaScoreAutoReview` is enabled:
- Pages are automatically scored when created
- Pages are re-scored when edited
- Scoring happens asynchronously via job queue (doesn't slow down saves)

### Manual Scoring via API

You can also score pages programmatically using the API:

```bash
# Get cached score
api.php?action=wandascore&page=Main_Page&format=json

# Force refresh score
api.php?action=wandascore&page=Main_Page&refresh=true&format=json
```

**API Response Example:**
```json
{
  "wandascore": {
    "overall_score": 85,
    "factors": {
      "bias": {
        "score": 90,
        "details": "The content appears neutral and unbiased..."
      },
      "llm_generated": {
        "score": 88,
        "details": "The writing style suggests human authorship..."
      },
      // ... other factors
    },
    "timestamp": "20251026153045",
    "page_id": 1,
    "page_title": "Main_Page"
  }
}
```

## How It Works

1. **Content Analysis**: When a page is saved (or manually scored), WandaScore extracts the page content
2. **AI Review**: For each of the 5 quality factors, it sends a specialized prompt to Wanda's LLM
3. **Score Calculation**: Each factor receives a score (0-100) with detailed feedback
4. **Weighted Average**: An overall score is calculated using weighted averages:
   - LLM Detection: 1.5x weight (most important)
   - Bias: 1.2x weight
   - Grammar: 1.1x weight
   - Language Quality: 1.0x weight
   - Conciseness: 0.8x weight
5. **Caching**: Results are stored in the database for fast retrieval
6. **Display**: Scores are shown via the tile and detailed review page

## Scoring Factors Explained

### 1. Bias Detection (⚖️)
Analyzes content for neutral point of view. Identifies:
- Political or ideological bias
- Promotional language
- Loaded terms or weasel words

### 2. LLM-Generated Content (🤖)
Detects AI-generated text by looking for:
- Repetitive patterns
- Generic phrasing
- Lack of personal voice or examples

### 3. Language Quality (🌐)
Evaluates overall language quality:
- Clarity and readability
- Professional tone
- Appropriate vocabulary

### 4. Grammar & Spelling (✍️)
Checks for:
- Grammatical errors
- Spelling mistakes
- Punctuation issues

### 5. Conciseness (📝)
Assesses whether content is:
- Free from unnecessary verbosity
- Well-structured
- To the point

## For Manual Reviewers

WandaScore is designed to assist manual content reviewers:

1. **Quick Assessment**: The score tile provides an at-a-glance quality indicator
2. **Detailed Insights**: The review page explains specific issues to address
3. **Prioritization**: Focus on pages with lower scores first
4. **Educational**: Helps editors understand what makes quality content

## Troubleshooting

### Score tile not appearing
- Check that `$wgWandaScoreShowTile` is `true`
- Verify the current namespace is in `$wgWandaScoreNamespaces`
- Clear browser cache and MediaWiki resource loader cache

### "Error generating score"
- Ensure Wanda extension is installed and configured
- Check that Wanda's LLM provider is working
- Verify Elasticsearch is running (required by Wanda)
- Check MediaWiki logs for details

### Scores not updating automatically
- Verify `$wgWandaScoreAutoReview` is `true`
- Check job queue is running: `php maintenance/runJobs.php`
- For immediate scoring, use the "Refresh Score" button

### Slow performance
- Scoring is CPU-intensive; consider:
  - Using a faster LLM model
  - Disabling auto-review for frequently edited pages
  - Running job queue in background

## Security Considerations

- All LLM interactions go through Wanda extension's security layer
- Page content is sanitized before display
- Scores are cached to prevent API abuse
- Job queue prevents DoS from repeated saves

## License

This extension is licensed under GPL-2.0-or-later.

## Credits

- Built with [MediaWiki Codex](https://doc.wikimedia.org/codex/latest/)
- Powered by [Wanda extension](https://www.mediawiki.org/wiki/Extension:Wanda)
- Author: Sanjay Thiyagarajan

## Support

For issues, questions, or contributions:
- Report bugs on the issue tracker
- Discuss on the MediaWiki extension talk page
- See Wanda extension documentation for LLM configuration help

---

**Note**: This extension requires the Wanda extension to be installed and properly configured. WandaScore cannot function without Wanda's AI capabilities.
