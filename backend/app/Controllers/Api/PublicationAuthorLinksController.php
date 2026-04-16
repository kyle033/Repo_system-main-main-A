<?php

namespace App\Controllers\Api;

use App\Models\FacultyModel;
use App\Models\PublicationAuthorLinkModel;
use App\Models\PublicationNonFacultyAuthorModel;
use App\Models\PublicationModel;
use CodeIgniter\RESTful\ResourceController;

class PublicationAuthorLinksController extends ResourceController
{
    protected $modelName = 'App\Models\PublicationAuthorLinkModel';
    protected $format    = 'json';

    public function __construct()
    {
        $origin = getenv('FRONTEND_ORIGIN') ?: 'http://localhost:5173';
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    public function pending()
    {
        try {
            $search = $this->request->getGet('search');
            $page = $this->request->getGet('page') ?? 1;
            $perPage = $this->request->getGet('per_page') ?? 20;

            $builder = $this->model->builder();
            $builder->select('publication_author_links.*');
            $builder->select('publications.title as publication_title');
            $builder->select('publications.year as publication_year');
            $builder->join('publications', 'publications.id = publication_author_links.publication_id', 'left');
            $builder->where('publication_author_links.status', 'pending');

            if ($search) {
                $builder->groupStart()
                        ->like('publication_author_links.author_name', $search)
                        ->orLike('publications.title', $search)
                        ->groupEnd();
            }

            $total = $builder->countAllResults(false);

            $rows = $builder
                ->orderBy('publication_author_links.id', 'DESC')
                ->limit($perPage, ($page - 1) * $perPage)
                ->get()
                ->getResultArray();

            return $this->respond([
                'status' => 'success',
                'data' => $rows,
                'pagination' => [
                    'current_page' => (int)$page,
                    'per_page' => (int)$perPage,
                    'total' => (int)$total,
                    'total_pages' => (int)ceil($total / $perPage)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fail([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function authors()
    {
        try {
            $search = trim((string)($this->request->getGet('search') ?? ''));
            $page = max(1, (int)($this->request->getGet('page') ?? 1));
            $perPage = 20;

            $rows = $this->model->builder()
                ->select('publication_author_links.author_name, publication_author_links.faculty_id, publication_author_links.status')
                ->select('publications.id as publication_id, publications.title as publication_title, publications.year as publication_year')
                ->select('faculty.name as faculty_name')
                ->join('publications', 'publications.id = publication_author_links.publication_id', 'left')
                ->join('faculty', 'faculty.id = publication_author_links.faculty_id', 'left')
                ->where('publication_author_links.status', 'confirmed')
                ->orderBy('publication_author_links.author_name', 'ASC')
                ->get()
                ->getResultArray();

            $nonFacultyRows = (new PublicationNonFacultyAuthorModel())
                ->select('publication_id, author_name, author_type')
                ->findAll();

            $nonFacultyLookup = [];
            foreach ($nonFacultyRows as $row) {
                $key = (int)($row['publication_id'] ?? 0) . '|' . $this->normalizeAuthorName((string)($row['author_name'] ?? ''));
                $nonFacultyLookup[$key] = strtolower(trim((string)($row['author_type'] ?? '')));
            }

            $authors = [];
            foreach ($rows as $row) {
                $authorName = trim((string)($row['author_name'] ?? ''));
                if ($authorName === '') {
                    continue;
                }

                $authorKey = $this->normalizeAuthorName($authorName);
                if ($authorKey === '') {
                    continue;
                }

                if (!isset($authors[$authorKey])) {
                    $authors[$authorKey] = [
                        'author_name' => $authorName,
                        'faculty_id' => null,
                        'faculty_name' => '',
                        'author_category' => 'Pending',
                        'publication_count' => 0,
                        'latest_year' => null,
                        'publications' => [],
                    ];
                }

                $publicationId = (int)($row['publication_id'] ?? 0);
                $publicationTitle = trim((string)($row['publication_title'] ?? 'Untitled'));
                $publicationYear = $row['publication_year'] !== null ? (int)$row['publication_year'] : null;
                $facultyId = $row['faculty_id'] !== null ? (int)$row['faculty_id'] : null;
                $facultyName = trim((string)($row['faculty_name'] ?? ''));
                $status = strtolower(trim((string)($row['status'] ?? 'pending')));

                if ($facultyId) {
                    $authors[$authorKey]['faculty_id'] = $facultyId;
                    $authors[$authorKey]['faculty_name'] = $facultyName;
                    $authors[$authorKey]['author_category'] = 'Faculty';
                } else {
                    $nonFacultyKey = $publicationId . '|' . $this->normalizeAuthorName($authorName);
                    $nonFacultyType = $nonFacultyLookup[$nonFacultyKey] ?? '';
                    if ($nonFacultyType !== '') {
                        $authors[$authorKey]['author_category'] = ucfirst($nonFacultyType) . ' Non-Faculty';
                    } elseif ($authors[$authorKey]['author_category'] !== 'Faculty') {
                        $authors[$authorKey]['author_category'] = ucfirst($status);
                    }
                }

                if ($publicationId && !isset($authors[$authorKey]['publications'][$publicationId])) {
                    $authors[$authorKey]['publications'][$publicationId] = [
                        'id' => $publicationId,
                        'title' => $publicationTitle,
                        'year' => $publicationYear,
                    ];
                }

                if ($publicationYear !== null && ($authors[$authorKey]['latest_year'] === null || $publicationYear > $authors[$authorKey]['latest_year'])) {
                    $authors[$authorKey]['latest_year'] = $publicationYear;
                }
            }

            $data = array_values(array_map(static function (array $author) {
                $author['publications'] = array_values($author['publications']);
                $author['publication_count'] = count($author['publications']);
                usort($author['publications'], static function (array $a, array $b) {
                    return (int)($b['year'] ?? 0) <=> (int)($a['year'] ?? 0);
                });
                return $author;
            }, $authors));

            if ($search !== '') {
                $needle = $this->normalizeAuthorName($search);
                $data = array_values(array_filter($data, function (array $author) use ($needle) {
                    $haystack = [
                        $author['author_name'] ?? '',
                        $author['faculty_name'] ?? '',
                        $author['author_category'] ?? '',
                        implode(' ', array_map(static fn(array $pub) => (string)($pub['title'] ?? ''), $author['publications'] ?? [])),
                    ];

                    return str_contains($this->normalizeAuthorName(implode(' ', $haystack)), $needle);
                }));
            }

            usort($data, static function (array $a, array $b) {
                return strcasecmp((string)($a['author_name'] ?? ''), (string)($b['author_name'] ?? ''));
            });

            $total = count($data);
            $offset = ($page - 1) * $perPage;
            $paged = array_slice($data, $offset, $perPage);

            return $this->respond([
                'status' => 'success',
                'data' => $paged,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int)ceil($total / $perPage),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fail([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function byPublication($publicationId = null)
    {
        try {
            if (!$publicationId) {
                return $this->fail([
                    'status' => 'error',
                    'message' => 'publication_id is required'
                ], 400);
            }

            $status = $this->request->getGet('status');

            $builder = $this->model->builder();
            $builder->select('publication_author_links.*');
            $builder->select('faculty.name as faculty_name');
            $builder->join('faculty', 'faculty.id = publication_author_links.faculty_id', 'left');
            $builder->where('publication_author_links.publication_id', $publicationId);

            if ($status) {
                $builder->where('publication_author_links.status', $status);
            }

            $rows = $builder
                ->orderBy('publication_author_links.id', 'ASC')
                ->get()
                ->getResultArray();

            return $this->respond([
                'status' => 'success',
                'data' => $rows
            ]);
        } catch (\Exception $e) {
            return $this->fail([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function create()
    {
        try {
            $data = $this->request->getJSON(true);
            $publicationId = $data['publication_id'] ?? null;
            $authorName = $data['author_name'] ?? null;
            $facultyId = $data['faculty_id'] ?? null;
            $status = $data['status'] ?? null;
            $nonFacultyName = trim((string)($data['non_faculty_author_name'] ?? ''));
            $nonFacultyType = strtolower(trim((string)($data['non_faculty_type'] ?? '')));

            if (!$publicationId || !$authorName) {
                return $this->fail([
                    'status' => 'error',
                    'message' => 'publication_id and author_name are required'
                ], 400);
            }

            if (($status ?? '') === 'confirmed' && !$facultyId && $nonFacultyName === '') {
                return $this->fail([
                    'status' => 'error',
                    'message' => 'faculty_id or non_faculty_author_name is required to confirm'
                ], 400);
            }

            $existing = $this->model->where('publication_id', $publicationId)
                ->where('author_name', $authorName)
                ->first();
            if ($existing) {
                return $this->respond([
                    'status' => 'success',
                    'message' => 'Match already exists',
                    'data' => $existing
                ]);
            }

            $payload = [
                'publication_id' => $publicationId,
                'author_name' => $authorName,
                'faculty_id' => $facultyId,
                'status' => $status ?? ($facultyId ? 'confirmed' : 'pending'),
                'match_type' => $facultyId ? 'manual' : 'auto'
            ];

            if (!$this->model->insert($payload)) {
                return $this->fail([
                    'status' => 'error',
                    'message' => 'Failed to create match',
                    'errors' => $this->model->errors()
                ], 400);
            }

            if (($payload['status'] ?? '') === 'confirmed' && !empty($payload['faculty_id'])) {
                $insertedId = (int)$this->model->getInsertID();
                $this->syncPublicationAuthorToFacultyName(
                    (int)$payload['publication_id'],
                    (string)$payload['author_name'],
                    (int)$payload['faculty_id'],
                    $insertedId
                );
            } elseif (($payload['status'] ?? '') === 'confirmed' && $nonFacultyName !== '') {
                if (!in_array($nonFacultyType, ['internal', 'external', 'international'], true)) {
                    $nonFacultyType = 'external';
                }

                $insertedId = (int)$this->model->getInsertID();
                $canonicalName = $this->createOrGetNonFacultyAuthor(
                    (int)$payload['publication_id'],
                    $nonFacultyName,
                    $nonFacultyType
                );
                $this->syncPublicationAuthorToCanonicalName(
                    (int)$payload['publication_id'],
                    (string)$payload['author_name'],
                    $canonicalName,
                    $insertedId
                );
            }

            return $this->respondCreated([
                'status' => 'success',
                'message' => 'Match created'
            ]);
        } catch (\Exception $e) {
            return $this->fail([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update($id = null)
    {
        try {
            $data = $this->request->getJSON(true);

            $existing = $this->model->find($id);
            if (!$existing) {
                return $this->failNotFound('Match not found');
            }

            $status = $data['status'] ?? null;
            $facultyId = $data['faculty_id'] ?? null;
            $nonFacultyName = trim((string)($data['non_faculty_author_name'] ?? ''));
            $nonFacultyType = strtolower(trim((string)($data['non_faculty_type'] ?? '')));

            if ($status === 'confirmed' && !$facultyId && $nonFacultyName === '') {
                return $this->fail([
                    'status' => 'error',
                    'message' => 'faculty_id or non_faculty_author_name is required to confirm a match'
                ], 400);
            }

            $payload = [
                'status' => $status ?? 'confirmed',
                'faculty_id' => $facultyId,
                'match_type' => 'manual'
            ];

            if (!$this->model->update($id, $payload)) {
                return $this->fail([
                    'status' => 'error',
                    'message' => 'Failed to update match'
                ], 400);
            }

            if (($payload['status'] ?? '') === 'confirmed' && !empty($payload['faculty_id'])) {
                $this->syncPublicationAuthorToFacultyName(
                    (int)$existing['publication_id'],
                    (string)($existing['author_name'] ?? ''),
                    (int)$payload['faculty_id'],
                    (int)$id
                );
            } elseif (($payload['status'] ?? '') === 'confirmed' && $nonFacultyName !== '') {
                if (!in_array($nonFacultyType, ['internal', 'external', 'international'], true)) {
                    return $this->fail([
                        'status' => 'error',
                        'message' => 'Invalid non_faculty_type'
                    ], 400);
                }

                $canonicalName = $this->createOrGetNonFacultyAuthor(
                    (int)$existing['publication_id'],
                    $nonFacultyName,
                    $nonFacultyType
                );
                $this->syncPublicationAuthorToCanonicalName(
                    (int)$existing['publication_id'],
                    (string)($existing['author_name'] ?? ''),
                    $canonicalName,
                    (int)$id
                );
            }

            return $this->respond([
                'status' => 'success',
                'message' => 'Match updated'
            ]);
        } catch (\Exception $e) {
            return $this->fail([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function syncPublicationAuthorToFacultyName(
        int $publicationId,
        string $sourceAuthorName,
        int $facultyId,
        int $linkId
    ): void {
        if ($publicationId <= 0 || $facultyId <= 0) {
            return;
        }

        $facultyModel = new FacultyModel();
        $faculty = $facultyModel->find($facultyId);
        $facultyName = trim((string)($faculty['name'] ?? ''));
        if ($facultyName === '') {
            return;
        }

        $this->syncPublicationAuthorToCanonicalName($publicationId, $sourceAuthorName, $facultyName, $linkId);
    }

    private function syncPublicationAuthorToCanonicalName(
        int $publicationId,
        string $sourceAuthorName,
        string $canonicalAuthorName,
        int $linkId
    ): void {
        $canonicalAuthorName = trim($canonicalAuthorName);
        if ($canonicalAuthorName === '') {
            return;
        }

        $publicationModel = new PublicationModel();
        $publication = $publicationModel->find($publicationId);
        if (!$publication) {
            return;
        }

        $authors = $this->parseAuthors((string)($publication['authors'] ?? ''));
        if (!$authors) {
            $authors = [$canonicalAuthorName];
        } else {
            $normalizedSource = $this->normalizeAuthorName($sourceAuthorName);
            $replaced = false;

            foreach ($authors as $index => $author) {
                if ($this->normalizeAuthorName($author) === $normalizedSource) {
                    $authors[$index] = $canonicalAuthorName;
                    $replaced = true;
                    break;
                }
            }

            if (!$replaced) {
                $authors[] = $canonicalAuthorName;
            }

            // Keep order, remove duplicates by normalized name.
            $seen = [];
            $deduped = [];
            foreach ($authors as $author) {
                $author = trim((string)$author);
                if ($author === '') {
                    continue;
                }
                $key = $this->normalizeAuthorName($author);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $deduped[] = $author;
            }
            $authors = $deduped;
        }

        $publicationModel->update($publicationId, [
            'authors' => json_encode(array_values($authors))
        ]);

        $this->model->update($linkId, [
            'author_name' => $canonicalAuthorName
        ]);
    }

    private function createOrGetNonFacultyAuthor(
        int $publicationId,
        string $authorName,
        string $authorType
    ): string {
        $model = new PublicationNonFacultyAuthorModel();
        $cleanName = trim(preg_replace('/\s+/', ' ', $authorName) ?? '');

        $db = \Config\Database::connect();
        $escapedName = $db->escape(strtolower($cleanName));
        $existing = $model->builder()
            ->select('id, author_name')
            ->where('publication_id', $publicationId)
            ->where('author_type', $authorType)
            ->where("LOWER(TRIM(author_name)) = {$escapedName}", null, false)
            ->get()
            ->getRowArray();

        if ($existing) {
            return (string)$existing['author_name'];
        }

        $model->insert([
            'publication_id' => $publicationId,
            'author_name' => $cleanName,
            'author_type' => $authorType,
        ]);

        return $cleanName;
    }

    private function parseAuthors(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map(
                static fn($item) => trim((string)$item),
                $decoded
            )));
        }

        $normalized = str_ireplace([' and ', '&'], ',', $trimmed);
        return array_values(array_filter(array_map(
            static fn($item) => trim((string)$item),
            preg_split('/\s*[,;\/]\s*/', $normalized) ?: []
        )));
    }

    private function normalizeAuthorName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }
}
